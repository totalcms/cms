<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Filesystem;

use PHPUnit\Framework\TestCase;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

final class PathUtilsTest extends TestCase
{
	// ──────────────────────────────────────────────────────────────────────
	// buildPath
	// ──────────────────────────────────────────────────────────────────────

	public function testBuildPathCollectionOnly(): void
	{
		$this->assertSame('blog', PathUtils::buildPath('blog'));
	}

	public function testBuildPathWithObjectIdAndProperty(): void
	{
		$this->assertSame('blog/post-1/image', PathUtils::buildPath('blog', 'post-1', 'image'));
	}

	public function testBuildPathWithFilename(): void
	{
		$this->assertSame(
			'blog/post-1/image/photo.jpg',
			PathUtils::buildPath('blog', 'post-1', 'image', 'photo.jpg')
		);
	}

	public function testBuildPathWithFilenameAndSubpath(): void
	{
		$this->assertSame(
			'blog/post-1/mydeck/item-3/photo.jpg',
			PathUtils::buildPath('blog', 'post-1', 'mydeck', 'photo.jpg', 'item-3')
		);
	}

	public function testBuildPathWithSubpathAndNoFilenameYieldsDirectoryPath(): void
	{
		// Regression guard for the Phase 1 change: subpath without filename
		// previously produced `blog/post-1/mydeck` (subpath silently dropped).
		// Now it produces `blog/post-1/mydeck/item-3` so callers can address
		// nested directories (used by PropertyRepository::listPropertyFiles).
		$this->assertSame(
			'blog/post-1/mydeck/item-3',
			PathUtils::buildPath('blog', 'post-1', 'mydeck', null, 'item-3')
		);
	}

	public function testBuildPathSanitizesSubpath(): void
	{
		$this->assertSame(
			'blog/post-1/mydeck/item-3/photo.jpg',
			PathUtils::buildPath('blog', 'post-1', 'mydeck', 'photo.jpg', '/item-3/')
		);
	}

	public function testBuildPathSlugifiesCollectionAndIdAndProperty(): void
	{
		$this->assertSame(
			'my-blog/post-id/my-prop/photo.jpg',
			PathUtils::buildPath('My Blog', 'post id', 'My Prop', 'photo.jpg')
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// sanitizeSubpath — security boundary
	// ──────────────────────────────────────────────────────────────────────

	public function testSanitizeSubpathStripsParentTraversal(): void
	{
		$this->assertSame('etc/passwd', PathUtils::sanitizeSubpath('../etc/passwd'));
	}

	public function testSanitizeSubpathStripsMultipleParentSegments(): void
	{
		$this->assertSame('etc/passwd', PathUtils::sanitizeSubpath('../../etc/passwd'));
	}

	public function testSanitizeSubpathHandlesOverlappingDots(): void
	{
		// `....` contains two `..` and should be fully stripped.
		$this->assertSame('', PathUtils::sanitizeSubpath('....'));
	}

	public function testSanitizeSubpathNormalizesBackslashes(): void
	{
		$this->assertSame('item-3/sub', PathUtils::sanitizeSubpath('item-3\\sub'));
	}

	public function testSanitizeSubpathStripsBackslashTraversal(): void
	{
		// Windows-style traversal: `..\..\etc` → after backslash normalize and
		// `..` stripping, just `etc` remains.
		$this->assertSame('etc', PathUtils::sanitizeSubpath('..\\..\\etc'));
	}

	public function testSanitizeSubpathTrimsLeadingAndTrailingSlashes(): void
	{
		$this->assertSame('item-3/sub', PathUtils::sanitizeSubpath('/item-3/sub/'));
	}

	public function testSanitizeSubpathPassesNormalNestedPath(): void
	{
		$this->assertSame('item-3/styledtext', PathUtils::sanitizeSubpath('item-3/styledtext'));
	}

	public function testSanitizeSubpathReturnsEmptyForEmptyInput(): void
	{
		$this->assertSame('', PathUtils::sanitizeSubpath(''));
	}

	public function testBuildPathStripsTraversalEvenViaSubpath(): void
	{
		// Defense-in-depth: even if a malicious subpath sneaks past callers,
		// buildPath must keep the resulting on-disk path under the property dir.
		$result = PathUtils::buildPath('blog', 'post-1', 'mydeck', 'photo.jpg', '../../../etc/passwd');
		$this->assertStringStartsWith('blog/post-1/mydeck/', $result);
		$this->assertStringNotContainsString('..', $result);
	}

	// ──────────────────────────────────────────────────────────────────────
	// splitPath
	// ──────────────────────────────────────────────────────────────────────

	public function testSplitPathSingleFilename(): void
	{
		$this->assertSame(['photo.jpg', null], PathUtils::splitPath('photo.jpg'));
	}

	public function testSplitPathWithSubpath(): void
	{
		$this->assertSame(['photo.jpg', 'item-3'], PathUtils::splitPath('item-3/photo.jpg'));
	}

	public function testSplitPathDeepSubpath(): void
	{
		$this->assertSame(
			['photo.jpg', 'mydeck/item-3/styledtext'],
			PathUtils::splitPath('mydeck/item-3/styledtext/photo.jpg')
		);
	}

	public function testSplitPathEmptyInput(): void
	{
		$this->assertSame(['', null], PathUtils::splitPath(''));
	}

	public function testSplitPathSanitizesBeforeSplitting(): void
	{
		// Traversal segments stripped before split; leading slash trimmed.
		$this->assertSame(['photo.jpg', 'etc'], PathUtils::splitPath('/../etc/photo.jpg'));
	}

	public function testSplitPathDecodesFilename(): void
	{
		// The depot-style `+`-as-space encoding gets decoded in the filename
		// (path segments don't auto-decode `+`).
		$this->assertSame(['my file.pdf', 'docs'], PathUtils::splitPath('docs/my+file.pdf'));
	}

	public function testSplitPathDecodesPercentEncodedFilename(): void
	{
		$this->assertSame(['my file.pdf', null], PathUtils::splitPath('my%20file.pdf'));
	}

	// ──────────────────────────────────────────────────────────────────────
	// decodeFilename
	// ──────────────────────────────────────────────────────────────────────

	public function testDecodeFilenameConvertsPlusToSpace(): void
	{
		$this->assertSame('my file.pdf', PathUtils::decodeFilename('my+file.pdf'));
	}

	public function testDecodeFilenameDecodesPercentEncoding(): void
	{
		$this->assertSame('my file.pdf', PathUtils::decodeFilename('my%20file.pdf'));
	}

	public function testDecodeFilenameIsNoOpForPlainFilename(): void
	{
		$this->assertSame('photo.jpg', PathUtils::decodeFilename('photo.jpg'));
	}
}
