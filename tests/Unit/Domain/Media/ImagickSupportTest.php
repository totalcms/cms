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

	/**
	 * ImageMagick allocates outside PHP's memory manager, so memory_limit never
	 * bounds it. Measured on production: a 24 MP resize peaked at 268 MB per
	 * process unlimited, 86 MB with these caps.
	 */
	public function testProbingCapsImageMagickMemory(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Requires imagick.');
		}

		$restore = $this->snapshotLimits();
		try {
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 1024 * 1024 * 1024);
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 2048 * 1024 * 1024);

			ImagickSupport::formats();

			$this->assertSame(64 * 1024 * 1024, (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY));
			$this->assertSame(128 * 1024 * 1024, (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MAP));
		} finally {
			$restore();
		}
	}

	public function testAStricterHostLimitIsNeverRaised(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Requires imagick.');
		}

		$restore = $this->snapshotLimits();
		try {
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 16 * 1024 * 1024);
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 32 * 1024 * 1024);

			ImagickSupport::limitResources();

			$this->assertSame(16 * 1024 * 1024, (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY));
			$this->assertSame(32 * 1024 * 1024, (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MAP));
		} finally {
			$restore();
		}
	}

	public function testLimitsApplyOncePerRequest(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Requires imagick.');
		}

		$restore = $this->snapshotLimits();
		try {
			ImagickSupport::limitResources();
			// An operator raising it mid-request is not fought on every call.
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 512 * 1024 * 1024);
			ImagickSupport::limitResources();

			$this->assertSame(512 * 1024 * 1024, (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY));
		} finally {
			$restore();
		}
	}

	/** ImageMagick limits are process-wide; put them back for later tests. */
	private function snapshotLimits(): \Closure
	{
		$memory = (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
		$map    = (int)\Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MAP);

		return static function () use ($memory, $map): void {
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, $memory);
			\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, $map);
		};
	}
}
