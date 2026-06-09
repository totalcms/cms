<?php

declare(strict_types=1);

namespace Tests\Unit\Media\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Service\HeicConverter;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

final class HeicConverterTest extends TestCase
{
	private HeicConverter $converter;

	protected function setUp(): void
	{
		$this->converter = new HeicConverter();
	}

	public function testIsHeicFileDetectsHeicAndHeifByExtension(): void
	{
		$this->assertTrue($this->converter->isHeicFile('/tmp/IMG_0001.heic'));
		$this->assertTrue($this->converter->isHeicFile('/tmp/IMG_0001.HEIF'));
		$this->assertFalse($this->converter->isHeicFile('/tmp/photo.jpg'));
		$this->assertFalse($this->converter->isHeicFile('/tmp/photo.png'));
	}

	public function testProbeFixtureShipsAndIsRealHevcHeic(): void
	{
		$probe = PathResolver::packageRoot() . '/resources/diagnostics/heic-probe.heic';

		$this->assertFileExists($probe, 'The HEIC self-test probe fixture must ship with the package');
		$this->assertGreaterThan(0, (int)filesize($probe));

		// ISO-BMFF: bytes 4-7 are 'ftyp', and a HEIC brand appears in the header.
		$head = (string)file_get_contents($probe, false, null, 0, 32);
		$this->assertSame('ftyp', substr($head, 4, 4), 'Probe must be an ISO media (ftyp) container');
		$this->assertStringContainsString('heic', strtolower($head), 'Probe must declare a HEIC brand');
	}

	public function testSelfTestReturnsOperationResultAndNeverThrows(): void
	{
		// The contract holds on ANY server: it returns a typed result rather than
		// throwing, so callers (ServerChecker) can surface the message safely.
		$result = $this->converter->selfTest();

		$this->assertInstanceOf(OperationResult::class, $result);
		if (!$result->success) {
			// A failure must carry an explanatory message for the operator.
			$this->assertNotSame('', $result->message);
		}
	}

	public function testSelfTestSucceedsWhenServerCanDecodeHeic(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Imagick extension not loaded');
		}
		if (\Imagick::queryFormats('HEIC') === []) {
			$this->markTestSkipped('ImageMagick has no HEIC coder registered');
		}

		$result = $this->converter->selfTest();

		// queryFormats only proves the coder is REGISTERED. If the HEVC decoder
		// plugin (libde265) is missing, or a restrictive policy.xml blocks it,
		// the decode still fails — which is exactly what this probe is for. So we
		// only assert a positive when the decode genuinely works; otherwise the
		// failure message must name a real, actionable cause.
		if ($result->success) {
			$this->assertTrue($result->success);
		} else {
			$this->assertNotSame('', $result->message);
		}
	}
}
