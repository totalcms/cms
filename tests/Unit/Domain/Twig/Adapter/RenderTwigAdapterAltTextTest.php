<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * `cms.render.alt()` and its gallery siblings decide the alt text and captions
 * on public pages.
 *
 * These are the rendering faults nobody sees. A page with a wrong heading gets
 * reported the same day; a page whose images all carry an empty alt attribute
 * looks perfect to everyone sighted, and shows up only as an accessibility
 * failure and lost image search traffic. So the fallback chain is the
 * behaviour, and every rung of it is worth holding.
 */
final class RenderTwigAdapterAltTextTest extends TestCase
{
	private MockObject&DataTwigAdapter $data;
	private MockObject&MediaTwigAdapter $media;
	private RenderTwigAdapter $adapter;

	protected function setUp(): void
	{
		$this->data  = $this->createMock(DataTwigAdapter::class);
		$this->media = $this->createMock(MediaTwigAdapter::class);

		$config      = $this->createMock(Config::class);
		$config->api = '';

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->adapter = new RenderTwigAdapter(
			$this->createMock(HtmxRenderer::class),
			$config,
			$this->data,
			$this->media,
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(SchemaFetcher::class),
			$this->createMock(GridRenderer::class),
			$loggerFactory,
		);
	}

	// ── alt(): a single image property ───────────────────────────────────────

	public function testPrefersTheAuthoredAltText(): void
	{
		// What the editor typed wins over anything derived, always.
		$alt = $this->adapter->alt([
			'image' => [
				'alt'  => 'A dog on a beach',
				'name' => 'DSC_0001.jpg',
				'exif' => ['title' => 'Untitled', 'description' => 'Camera default'],
			],
		]);

		$this->assertSame('A dog on a beach', $alt);
	}

	public function testFallsBackToTheExifTitle(): void
	{
		$alt = $this->adapter->alt([
			'image' => ['name' => 'DSC_0001.jpg', 'exif' => ['title' => 'Sunrise over the bay']],
		]);

		$this->assertSame('Sunrise over the bay', $alt);
	}

	public function testFallsBackToTheExifDescriptionBeforeTheFilename(): void
	{
		// Order matters: a camera-written description is poor alt text, but a
		// filename is worse.
		$alt = $this->adapter->alt([
			'image' => ['name' => 'DSC_0001.jpg', 'exif' => ['description' => 'Shot on a Nikon']],
		]);

		$this->assertSame('Shot on a Nikon', $alt);
	}

	public function testFallsBackToTheFilenameLast(): void
	{
		$alt = $this->adapter->alt(['image' => ['name' => 'a-dog.jpg']]);

		$this->assertSame('a-dog.jpg', $alt);
	}

	public function testTreatsAnEmptyAltAsAbsentRatherThanAsAnAnswer(): void
	{
		// An empty string is what an untouched field holds, so it has to fall
		// through — otherwise every image an editor never captioned renders
		// alt="" while better text sits right there in the EXIF.
		$alt = $this->adapter->alt([
			'image' => ['alt' => '', 'exif' => ['title' => 'Sunrise'], 'name' => 'x.jpg'],
		]);

		$this->assertSame('Sunrise', $alt);
	}

	public function testReturnsEmptyWhenThePropertyIsNotAnImage(): void
	{
		// Empty rather than a stringified array or a warning: an empty alt is
		// correct for a decorative or missing image.
		$this->assertSame('', $this->adapter->alt(['image' => 'not-an-image']));
		$this->assertSame('', $this->adapter->alt([]));
	}

	public function testReadsAnImageNestedInsideACard(): void
	{
		// Dotted properties address images inside cards and decks. Failing to
		// descend returns empty alt for every nested image on the site.
		$alt = $this->adapter->alt(
			['hero' => ['photo' => ['alt' => 'Nested alt']]],
			['property' => 'hero.photo'],
		);

		$this->assertSame('Nested alt', $alt);
	}

	public function testReturnsEmptyWhenTheNestedPathDoesNotResolve(): void
	{
		$alt = $this->adapter->alt(
			['hero' => ['photo' => 'not-an-image']],
			['property' => 'hero.missing'],
		);

		$this->assertSame('', $alt);
	}

	public function testLooksTheObjectUpWhenGivenAnId(): void
	{
		// The other calling convention: an id plus a collection rather than a
		// loaded object.
		$this->data->expects($this->once())->method('raw')
			->with('blog', 'post-1', 'image')
			->willReturn(['alt' => 'From storage']);

		$this->assertSame('From storage', $this->adapter->alt('post-1', ['collection' => 'blog']));
	}

	public function testDescendsADottedPathOnALookedUpObject(): void
	{
		// raw() is asked for the ROOT property; the remaining segments are
		// walked here. Asking for 'hero.photo' would simply not exist.
		$this->data->expects($this->once())->method('raw')
			->with('blog', 'post-1', 'hero')
			->willReturn(['photo' => ['alt' => 'Nested from storage']]);

		$alt = $this->adapter->alt('post-1', ['collection' => 'blog', 'property' => 'hero.photo']);

		$this->assertSame('Nested from storage', $alt);
	}

	// ── galleryAlt(): one image out of a gallery ─────────────────────────────

	public function testGalleryAltUsesTheSameChainDownToTheFilename(): void
	{
		$this->media->method('galleryImageData')->willReturn(['name' => 'in-gallery.jpg']);

		$this->assertSame('in-gallery.jpg', $this->adapter->galleryAlt(['gallery' => []], 'first'));
	}

	public function testGalleryAltIsEmptyForAnImageThatIsNotThere(): void
	{
		$this->media->method('galleryImageData')->willReturn(null);

		$this->assertSame('', $this->adapter->galleryAlt(['gallery' => []], 'missing'));
	}

	// ── galleryCaption(): visible text, so different rules ───────────────────

	public function testACaptionNeverFallsBackToTheFilename(): void
	{
		// The one deliberate difference from galleryAlt. A filename is
		// tolerable as alt text, which is read aloud in context; printed under
		// a photograph, "DSC_0001.jpg" is just wrong.
		$this->media->method('galleryImageData')->willReturn(['name' => 'DSC_0001.jpg']);

		$this->assertSame('', $this->adapter->galleryCaption(['gallery' => []], 'first'));
	}

	public function testACaptionStillPrefersAuthoredTextThenExif(): void
	{
		$this->media->method('galleryImageData')->willReturn([
			'alt'  => 'Authored caption',
			'exif' => ['title' => 'Exif title'],
			'name' => 'DSC_0001.jpg',
		]);

		$this->assertSame('Authored caption', $this->adapter->galleryCaption(['gallery' => []], 'first'));
	}

	public function testACaptionUsesTheExifTitleWhenNothingWasAuthored(): void
	{
		$this->media->method('galleryImageData')->willReturn([
			'exif' => ['title' => 'Exif title', 'description' => 'Exif description'],
			'name' => 'DSC_0001.jpg',
		]);

		$this->assertSame('Exif title', $this->adapter->galleryCaption(['gallery' => []], 'first'));
	}

	public function testACaptionFallsThroughToTheExifDescription(): void
	{
		$this->media->method('galleryImageData')->willReturn([
			'exif' => ['description' => 'Exif description'],
			'name' => 'DSC_0001.jpg',
		]);

		$this->assertSame('Exif description', $this->adapter->galleryCaption(['gallery' => []], 'first'));
	}

	public function testACaptionIsEmptyForAnImageThatIsNotThere(): void
	{
		$this->media->method('galleryImageData')->willReturn(null);

		$this->assertSame('', $this->adapter->galleryCaption(['gallery' => []], 'missing'));
	}
}
