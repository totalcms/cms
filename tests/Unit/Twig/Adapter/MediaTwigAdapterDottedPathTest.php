<?php

declare(strict_types=1);

namespace Tests\Unit\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

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
			['w' => 800, 'h' => 600, 'fit' => 'crop'],
			['collection' => 'blog', 'property' => 'mycard.image'],
		);

		$this->assertStringContainsString('w=800', $url);
		$this->assertStringContainsString('h=600', $url);
		$this->assertStringContainsString('fit=crop', $url);
	}
}
