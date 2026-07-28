<?php

declare(strict_types=1);

namespace Tests\Unit\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Passing an object ID (rather than the object) to `cms.render.galleryImage()`
 * inside a loop is the most common performance trap in user templates. Each
 * call re-hydrates the whole object and transforms it back to an array just to
 * read one property, so an N-image gallery rendered image-by-image was O(N^2) —
 * ~8s for a 200-image gallery, versus instant when the template hoisted the
 * object into a variable first.
 *
 * `MediaTwigAdapter` memoizes those lookups so both idioms cost the same. These
 * tests pin the memo: that it collapses repeat lookups, that its key actually
 * distinguishes different objects/properties/collections, and that the two
 * calling styles still agree on the data.
 */
final class MediaTwigAdapterCacheTest extends TestCase
{
	/** @var array<string,mixed> */
	private const GALLERY = [
		'photo-one' => ['name' => 'photo-one', 'size' => 100, 'width' => 10, 'height' => 10],
		'photo-two' => ['name' => 'photo-two', 'size' => 200, 'width' => 20, 'height' => 20],
	];

	/**
	 * @param array<string,mixed> $data
	 */
	private function objectReturning(array $data): ObjectData
	{
		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn($data);

		return $object;
	}

	private function adapter(ObjectFetcher $fetcher): MediaTwigAdapter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		// Config has public defaults; skip the constructor rather than mocking it.
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		return new MediaTwigAdapter($fetcher, $config, $loggerFactory);
	}

	public function testRepeatedLookupsForTheSameObjectFetchOnlyOnce(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchObject')
			->with('gallery', 'portfolio')
			->willReturn($this->objectReturning(['id' => 'portfolio', 'gallery' => self::GALLERY]));

		$adapter = $this->adapter($fetcher);

		// Mirrors a template looping over every image in the gallery.
		foreach (['photo-one', 'photo-two', 'photo-one'] as $name) {
			$this->assertSame($name, $adapter->galleryImageData('portfolio', $name)['name'] ?? null);
		}
	}

	public function testDistinctObjectsAreCachedSeparately(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->expects($this->exactly(2))
			->method('fetchObject')
			->willReturnCallback(fn (string $collection, string $id): ObjectData => $this->objectReturning([
				'id'      => $id,
				'gallery' => [$id . '-img' => ['name' => $id . '-img', 'size' => 1]],
			]));

		$adapter = $this->adapter($fetcher);

		$this->assertSame('portfolio-img', $adapter->galleryImageData('portfolio', 'portfolio-img')['name'] ?? null);
		$this->assertSame('archive-img', $adapter->galleryImageData('archive', 'archive-img')['name'] ?? null);
		// Both are now cached — neither should hit the fetcher again.
		$this->assertSame('portfolio-img', $adapter->galleryImageData('portfolio', 'portfolio-img')['name'] ?? null);
		$this->assertSame('archive-img', $adapter->galleryImageData('archive', 'archive-img')['name'] ?? null);
	}

	public function testDifferentPropertiesOnTheSameObjectAreCachedSeparately(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('fetchObject')->willReturn($this->objectReturning([
			'id'      => 'portfolio',
			'gallery' => self::GALLERY,
			'archive' => ['other' => ['name' => 'other', 'size' => 5]],
		]));

		$adapter = $this->adapter($fetcher);

		// A key that ignored `property` would serve the gallery data for both.
		$this->assertSame('photo-one', $adapter->galleryImageData('portfolio', 'photo-one')['name'] ?? null);
		$this->assertSame(
			'other',
			$adapter->galleryImageData('portfolio', 'other', ['property' => 'archive'])['name'] ?? null,
		);
	}

	public function testPassingTheObjectAgreesWithPassingTheId(): void
	{
		$data = ['id' => 'portfolio', 'gallery' => self::GALLERY];

		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('fetchObject')->willReturn($this->objectReturning($data));

		$adapter = $this->adapter($fetcher);

		// The whole point of the memo: the two template idioms are equivalent.
		$this->assertSame(
			$adapter->galleryImageData($data, 'photo-two'),
			$adapter->galleryImageData('portfolio', 'photo-two'),
		);
	}

	public function testMissingObjectIsNotRefetchedOnEveryCall(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->expects($this->once())
			->method('fetchObject')
			->willThrowException(new \UnexpectedValueException('missing'));

		$adapter = $this->adapter($fetcher);

		// A broken ID in a loop should not re-throw and re-log N times.
		$this->assertNull($adapter->galleryImageData('nope', 'photo-one'));
		$this->assertNull($adapter->galleryImageData('nope', 'photo-one'));
	}
}
