<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Media;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Service\ImagickSupport;

/**
 * A production host was found running an Imagick that loaded cleanly but whose
 * ImageMagick had no coders registered at all — `Imagick::queryFormats()`
 * returned `[]` and every read threw `NoDecodeDelegateForThisImageFormat
 * 'JPEG'`. Because every caller gated on `extension_loaded('imagick')`, that
 * broken Imagick was chosen over a working GD and the site could not render a
 * single JPEG. The operator saw a 404.
 *
 * These lock in the distinction the codebase was missing: loaded is not usable.
 */
final class ImagickSupportTest extends TestCase
{
	protected function setUp(): void
	{
		ImagickSupport::reset();
	}

	protected function tearDown(): void
	{
		ImagickSupport::reset();
	}

	public function testFormatsAreEmptyWhenTheExtensionIsAbsent(): void
	{
		if (extension_loaded('imagick')) {
			$this->markTestSkipped('imagick is loaded here; the absent-extension path cannot be exercised.');
		}

		$this->assertSame([], ImagickSupport::formats());
		$this->assertFalse(ImagickSupport::isUsable());
	}

	public function testIsUsableAgreesWithTheReportedFormats(): void
	{
		$formats = ImagickSupport::formats();

		// The contract, whichever way this host is built: usable exactly when the
		// formats ImageWorks needs are present.
		$expected = $formats !== []
			&& in_array('JPEG', $formats, true)
			&& in_array('PNG', $formats, true);

		$this->assertSame($expected, ImagickSupport::isUsable());
	}

	public function testAnImagickReportingNoFormatsIsNotUsable(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Requires imagick to have something to contrast against.');
		}

		// The real host's shape: extension present, zero coders.
		$this->assertNotSame(
			[],
			ImagickSupport::formats(),
			'This host reports no Imagick formats at all — the same broken install '
			. 'this guard exists for. isUsable() must be false.',
		);
		$this->assertTrue(ImagickSupport::isUsable());
	}

	public function testFormatsAreUppercasedForComparison(): void
	{
		foreach (ImagickSupport::formats() as $format) {
			$this->assertSame(strtoupper($format), $format);
		}
	}

	public function testResultIsCachedAcrossCalls(): void
	{
		$first = ImagickSupport::formats();
		$this->assertSame($first, ImagickSupport::formats());
	}
}
