<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Filesystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Infrastructure\Filesystem\UnsafePathException;

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

	// ──────────────────────────────────────────────────────────────────────
	// absolutePath
	// ──────────────────────────────────────────────────────────────────────

	public function testAbsolutePathJoinsBaseAndRelative(): void
	{
		$this->assertSame('/var/www/tcms-data/automations/x/handler/handler.php', PathUtils::absolutePath('/var/www/tcms-data', 'automations/x/handler/handler.php'));
	}

	public function testAbsolutePathNormalizesTrailingAndLeadingSlashes(): void
	{
		$this->assertSame('/var/www/tcms-data/a/b', PathUtils::absolutePath('/var/www/tcms-data/', '/a/b'));
		$this->assertSame('/var/www/tcms-data/a/b', PathUtils::absolutePath('/var/www/tcms-data', 'a/b'));
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

	/**
	 * GHSA-7p83-8x29-j3j3: the filename is decoded after `..` is stripped, so
	 * an encoded traversal (what Slim hands over for `%252e%252e%252f`) must be
	 * refused once decoded.
	 *
	 * @return array<string, array{string}>
	 */
	public static function encodedTraversalProvider(): array
	{
		return [
			'encoded dot-dot-slash'       => ['%2e%2e%2f%2e%2e%2f%2e%2e%2f.system%2fsecret.json'],
			'encoded slash in subpath'    => ['docs/%2e%2e%2f%2e%2e%2f.system%2fapikeys.json'],
			'encoded backslash'           => ['..%5c..%5c.system%5csite.key'],
			'encoded dot-dot only'        => ['%2e%2e'],
			'encoded dot only'            => ['docs/%2e'],
			'encoded NUL'                 => ['photo.jpg%00.txt'],
		];
	}

	#[DataProvider('encodedTraversalProvider')]
	public function testSplitPathRefusesEncodedTraversal(string $path): void
	{
		$this->expectException(UnsafePathException::class);
		PathUtils::splitPath($path);
	}

	public function testBuildPathRefusesMultiSegmentFilename(): void
	{
		$this->expectException(UnsafePathException::class);
		PathUtils::buildPath('x', 'y', 'z', '../../../.system/secret.json');
	}

	public function testBuildPathRefusesDotDotFilename(): void
	{
		$this->expectException(UnsafePathException::class);
		PathUtils::buildPath('x', 'y', 'z', '..');
	}

	public function testBuildPathKeepsDottedFilenames(): void
	{
		// Only whole `.`/`..` segments are refused: dots inside a name are fine.
		$this->assertSame('x/y/z/a..b.tar.gz', PathUtils::buildPath('x', 'y', 'z', 'a..b.tar.gz'));
		$this->assertSame('x/y/z/.cache', PathUtils::buildPath('x', 'y', 'z', '.cache'));
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

	// ──────────────────────────────────────────────────────────────────────
	// safeFilename
	// ──────────────────────────────────────────────────────────────────────

	public function testSafeFilenameLeavesPlainAsciiUntouched(): void
	{
		$this->assertSame('photo-01.jpg', PathUtils::safeFilename('photo-01.jpg'));
	}

	public function testSafeFilenameStripsExoticUnicodeAndCollapsesRuns(): void
	{
		// U+2017, U+00BA, spaces and '&' all become a single underscore each.
		$this->assertSame(
			'14_DOLPHIN_Front_seats_download_JPG_2500px.jpg',
			PathUtils::safeFilename("14\u{2017} DOLPHIN_Front seats\u{00BA}_download_JPG 2500px.jpg"),
		);
	}

	public function testSafeFilenameReplacesAmpersandAndSpaces(): void
	{
		$this->assertSame('G_Awed_5356.jpg', PathUtils::safeFilename('G&Awed 5356.jpg'));
	}

	public function testSafeFilenameStripsLeadingPathComponents(): void
	{
		$this->assertSame('file.jpg', PathUtils::safeFilename('/tmp/evil/../file.jpg'));
	}

	public function testSafeFilenameFallsBackWhenNameIsAllUnsafe(): void
	{
		$this->assertSame('file.jpg', PathUtils::safeFilename("\u{2017}\u{00BA}.jpg"));
	}

	public function testSafeFilenameHandlesNoExtension(): void
	{
		$this->assertSame('my_file', PathUtils::safeFilename('my file'));
	}

	public function testSafeFilenameTransliteratesAccentedLatin(): void
	{
		if (!class_exists(\Transliterator::class)) {
			$this->markTestSkipped('intl Transliterator not available');
		}
		$this->assertSame('cafe_resume.jpg', PathUtils::safeFilename('café résumé.jpg'));
	}

	public function testSafeFilenameTransliteratesFacadeAndNaive(): void
	{
		if (!class_exists(\Transliterator::class)) {
			$this->markTestSkipped('intl Transliterator not available');
		}
		$this->assertSame('naive_facade.png', PathUtils::safeFilename('naïve façade.png'));
	}

	public function testSafeFilenameTransliteratesNonLatinScript(): void
	{
		if (!class_exists(\Transliterator::class)) {
			$this->markTestSkipped('intl Transliterator not available');
		}
		$this->assertSame('Moskva.jpg', PathUtils::safeFilename('Москва.jpg'));
	}
}
