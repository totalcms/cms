<?php

declare(strict_types=1);

namespace Tests\Unit\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Tests for the dotted-property helpers used by `cms.media.imagePath` and
 * `cms.render.image` to address card- and deck-nested images.
 *
 * Examples of paths these helpers parse:
 *   "image"                 → top-level image field
 *   "mycard.image"          → image inside a card
 *   "mydeck.item-3.image"   → image inside a deck item
 */
final class MediaTwigAdapterDottedPathTest extends TestCase
{
	// ──────────────────────────────────────────────────────────────────────
	// splitDottedProperty
	// ──────────────────────────────────────────────────────────────────────

	public function testSplitDottedPropertyTopLevel(): void
	{
		$this->assertSame(['image', []], MediaTwigAdapter::splitDottedProperty('image'));
	}

	public function testSplitDottedPropertyCardChild(): void
	{
		$this->assertSame(['mycard', ['image']], MediaTwigAdapter::splitDottedProperty('mycard.image'));
	}

	public function testSplitDottedPropertyDeckChild(): void
	{
		$this->assertSame(
			['mydeck', ['item-3', 'image']],
			MediaTwigAdapter::splitDottedProperty('mydeck.item-3.image'),
		);
	}

	public function testSplitDottedPropertyEmptyString(): void
	{
		$this->assertSame(['', []], MediaTwigAdapter::splitDottedProperty(''));
	}

	// ──────────────────────────────────────────────────────────────────────
	// descendDottedPath
	// ──────────────────────────────────────────────────────────────────────

	public function testDescendDottedPathTopLevel(): void
	{
		$obj = ['image' => ['name' => 'photo.jpg']];

		$this->assertSame(
			['name' => 'photo.jpg'],
			MediaTwigAdapter::descendDottedPath($obj, 'image', []),
		);
	}

	public function testDescendDottedPathCardChild(): void
	{
		$obj = [
			'mycard' => [
				'image' => ['name' => 'photo.jpg', 'size' => 1000],
				'title' => 'My card',
			],
		];

		$this->assertSame(
			['name' => 'photo.jpg', 'size' => 1000],
			MediaTwigAdapter::descendDottedPath($obj, 'mycard', ['image']),
		);
	}

	public function testDescendDottedPathDeckChild(): void
	{
		$obj = [
			'mydeck' => [
				'item-3' => [
					'image' => ['name' => 'deck-image.jpg'],
				],
			],
		];

		$this->assertSame(
			['name' => 'deck-image.jpg'],
			MediaTwigAdapter::descendDottedPath($obj, 'mydeck', ['item-3', 'image']),
		);
	}

	public function testDescendDottedPathReturnsNullForMissingRoot(): void
	{
		$obj = ['mycard' => []];

		$this->assertNull(MediaTwigAdapter::descendDottedPath($obj, 'doesnt_exist', []));
	}

	public function testDescendDottedPathReturnsNullForMissingChild(): void
	{
		$obj = ['mycard' => ['title' => 'has title but no image']];

		$this->assertNull(MediaTwigAdapter::descendDottedPath($obj, 'mycard', ['image']));
	}

	public function testDescendDottedPathReturnsNullWhenIntermediateIsNotArray(): void
	{
		$obj = ['mycard' => 'not-an-array'];

		// Trying to descend "mycard.image" hits a string at "mycard" — should bail.
		$this->assertNull(MediaTwigAdapter::descendDottedPath($obj, 'mycard', ['image']));
	}

	// ──────────────────────────────────────────────────────────────────────
	// buildImageworksAPI — URL emission with dotted property
	// ──────────────────────────────────────────────────────────────────────

	public function testBuildImageworksApiTopLevelProperty(): void
	{
		$image = ['name' => 'photo.jpg', 'size' => 1000, 'hash' => 'abc'];

		$url = MediaTwigAdapter::buildImageworksAPI(
			'/api',
			'post-1',
			$image,
			[],
			['collection' => 'blog', 'property' => 'image'],
		);

		$this->assertStringContainsString('/imageworks/blog/post-1/image.jpg', $url);
	}

	public function testBuildImageworksApiCardChildEmitsSlashSeparatedSegments(): void
	{
		$image = ['name' => 'photo.jpg', 'size' => 2000, 'hash' => 'def'];

		$url = MediaTwigAdapter::buildImageworksAPI(
			'/api',
			'post-1',
			$image,
			[],
			['collection' => 'blog', 'property' => 'mycard.image'],
		);

		$this->assertStringContainsString('/imageworks/blog/post-1/mycard/image.jpg', $url);
	}

	public function testBuildImageworksApiDeckChildEmitsThreeSegments(): void
	{
		$image = ['name' => 'photo.jpg', 'size' => 3000, 'hash' => 'ghi'];

		$url = MediaTwigAdapter::buildImageworksAPI(
			'/api',
			'post-1',
			$image,
			[],
			['collection' => 'blog', 'property' => 'mydeck.item-3.image'],
		);

		$this->assertStringContainsString('/imageworks/blog/post-1/mydeck/item-3/image.jpg', $url);
	}

	public function testBuildImageworksApiReturnsEmptyForMissingName(): void
	{
		$url = MediaTwigAdapter::buildImageworksAPI('/api', 'post-1', [], [], ['property' => 'mycard.image']);

		$this->assertSame('', $url);
	}

	public function testBuildImageworksApiPreservesImageworksParams(): void
	{
		$image = ['name' => 'photo.jpg', 'size' => 1000, 'hash' => 'xyz'];

		$url = MediaTwigAdapter::buildImageworksAPI(
			'/api',
			'post-1',
			$image,
			['w'          => 800, 'h' => 600, 'fit' => 'crop'],
			['collection' => 'blog', 'property' => 'mycard.image'],
		);

		$this->assertStringContainsString('w=800', $url);
		$this->assertStringContainsString('h=600', $url);
		$this->assertStringContainsString('fit=crop', $url);
	}

	// ──────────────────────────────────────────────────────────────────────
	// download() / stream() — URL emission with dotted property
	//
	// These macros previously hardcoded `/{collection}/{id}/{property}`,
	// which 404'd for nested files. Dots → slashes is the same convention
	// used by buildImageworksAPI above.
	// ──────────────────────────────────────────────────────────────────────

	private function adapterWithApi(string $api): MediaTwigAdapter
	{
		// Bypass the constructor — `download()` and `stream()` only read
		// `$this->config->api`, so mocking the dependency graph is overkill.
		$adapter = (new \ReflectionClass(MediaTwigAdapter::class))->newInstanceWithoutConstructor();
		$config  = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		$config->api = $api;

		(new \ReflectionClass(MediaTwigAdapter::class))->getProperty('config')->setValue($adapter, $config);

		return $adapter;
	}

	public function testDownloadTopLevelEmitsSingleSegment(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->download('post-1', ['collection' => 'blog', 'property' => 'file']);

		$this->assertSame('https://example.com/download/blog/post-1/file', $url);
	}

	public function testDownloadCardChildEmitsSlashSeparatedSegments(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->download('post-1', ['collection' => 'blog', 'property' => 'mycard.file']);

		$this->assertSame('https://example.com/download/blog/post-1/mycard/file', $url);
	}

	public function testDownloadDeckChildEmitsThreeSegments(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->download('post-1', ['collection' => 'blog', 'property' => 'mydeck.item-3.file']);

		$this->assertSame('https://example.com/download/blog/post-1/mydeck/item-3/file', $url);
	}

	public function testDownloadAcceptsObjectArrayForId(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->download(['id' => 'post-1'], ['property' => 'mycard.file']);

		$this->assertSame('https://example.com/download/file/post-1/mycard/file', $url);
	}

	public function testDownloadAppendsEncryptedPasswordQueryParam(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->download('post-1', ['property' => 'mycard.file', 'pwd' => 'secret']);

		$this->assertStringStartsWith('https://example.com/download/file/post-1/mycard/file?pwd=', $url);
		$this->assertStringNotContainsString('pwd=secret', $url, 'Password should be encrypted before URL');
	}

	public function testStreamTopLevelEmitsSingleSegment(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->stream('post-1', ['collection' => 'blog', 'property' => 'video']);

		$this->assertSame('https://example.com/stream/blog/post-1/video', $url);
	}

	public function testStreamCardChildEmitsSlashSeparatedSegments(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->stream('post-1', ['collection' => 'blog', 'property' => 'mycard.file']);

		$this->assertSame('https://example.com/stream/blog/post-1/mycard/file', $url);
	}

	public function testStreamDeckChildEmitsThreeSegments(): void
	{
		$adapter = $this->adapterWithApi('https://example.com');

		$url = $adapter->stream('post-1', ['collection' => 'blog', 'property' => 'mydeck.item-3.file']);

		$this->assertSame('https://example.com/stream/blog/post-1/mydeck/item-3/file', $url);
	}
}
