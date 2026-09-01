<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Generator\BarcodeGenerator;
use TotalCMS\Domain\Twig\Adapter\BarcodeTwigAdapter;

/**
 * A bad barcode value must not take a page down.
 *
 * tc-lib-barcode 2.14 added real input validation, so values that used to
 * render (incorrectly) now throw. Without this softening, upgrading would
 * turn any live page carrying a bad barcode value into a 500 — a page that
 * worked yesterday breaking on `composer update`. The generator still
 * throws for programmatic callers; only the Twig surface degrades.
 */
final class BarcodeTwigAdapterFailSoftTest extends TestCase
{
	private BarcodeTwigAdapter $adapter;

	protected function setUp(): void
	{
		$this->adapter = new BarcodeTwigAdapter(new BarcodeGenerator());
	}

	public function testRendersAnHtmlCommentInsteadOfThrowing(): void
	{
		// A-D are reserved as CODABAR start/stop characters.
		$result = $this->adapter->codabar('A1234B');

		$this->assertStringStartsWith('<!-- barcode:', $result);
		$this->assertStringContainsString('reserved as start/stop', $result);
		$this->assertStringNotContainsString('<svg', $result);
	}

	public function testTheCommentCannotBreakOutOfItsOwnMarkup(): void
	{
		// The message can carry the offending value, so it must be escaped —
		// a barcode value is user data on a public page.
		$result = $this->adapter->custom('x', '"><script>alert(1)</script>');

		$this->assertStringStartsWith('<!-- barcode:', $result);
		$this->assertStringNotContainsString('<script>', $result);
	}

	public function testValidInputStillRendersABarcode(): void
	{
		$result = $this->adapter->codabar('1234');

		$this->assertStringContainsString('<svg', $result);
		$this->assertStringNotContainsString('<!-- barcode:', $result);
	}

	public function testTheGeneratorItselfStillThrows(): void
	{
		// Only the Twig surface softens. A caller that can handle the error
		// must still receive it.
		$this->expectException(\InvalidArgumentException::class);

		(new BarcodeGenerator())->codabar('A1234B');
	}

	public function testNumericValidationAlsoDegradesRatherThanThrows(): void
	{
		$result = $this->adapter->numeric('ABC123');

		$this->assertStringStartsWith('<!-- barcode:', $result);
		$this->assertStringContainsString('digits only', $result);
	}

	public function testProductLengthErrorDegradesRatherThanThrows(): void
	{
		$result = $this->adapter->product('12345');

		$this->assertStringStartsWith('<!-- barcode:', $result);
		$this->assertStringContainsString('Invalid product code length', $result);
	}
}
