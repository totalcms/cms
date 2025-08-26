<?php

declare(strict_types = 1);

namespace Tests\Unit\Media\Generator;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Generator\BarcodeGenerator;

final class BarcodeGeneratorTest extends TestCase
{
	private BarcodeGenerator $barcodeGenerator;

	protected function setUp(): void
	{
		$this->barcodeGenerator = new BarcodeGenerator();
	}

	public function testConstructor(): void
	{
		$generator = new BarcodeGenerator();
		$this->assertInstanceOf(BarcodeGenerator::class, $generator);
	}

	public function testCode128GeneratesHtmlByDefault(): void
	{
		$result = $this->barcodeGenerator->code128('TEST123');

		$this->assertIsString($result);
		$this->assertStringContainsString('<div class="barcode-container"', $result);
		$this->assertStringContainsString('data-type="C128"', $result);
		$this->assertStringContainsString('data-value="TEST123"', $result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testCode128GeneratesSvgFormat(): void
	{
		$result = $this->barcodeGenerator->code128('TEST123', ['format' => 'svg']);

		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
		$this->assertStringNotContainsString('<div class="barcode-container"', $result);
	}

	public function testCode128WithCustomOptions(): void
	{
		$result = $this->barcodeGenerator->code128('CUSTOM', [
			'width'  => 200,
			'height' => 50,
			'color'  => 'red',
			'format' => 'html',
		]);

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="C128"', $result);
		$this->assertStringContainsString('data-value="CUSTOM"', $result);
	}

	public function testCode39GeneratesValidOutput(): void
	{
		$result = $this->barcodeGenerator->code39('ABC123');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="C39"', $result);
		$this->assertStringContainsString('data-value="ABC123"', $result);
		$this->assertStringContainsString('<svg', $result);
	}

	public function testEan13ValidInput(): void
	{
		$result = $this->barcodeGenerator->ean13('123456789012');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="EAN13"', $result);
		$this->assertStringContainsString('data-value="123456789012"', $result);
	}

	public function testEan13ValidInputWith13Digits(): void
	{
		// Use a valid EAN-13 with correct check digit
		$result = $this->barcodeGenerator->ean13('1234567890128');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="EAN13"', $result);
		$this->assertStringContainsString('data-value="1234567890128"', $result);
	}

	public function testEan13ThrowsExceptionForInvalidInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('EAN-13 requires 12 or 13 digits');

		$this->barcodeGenerator->ean13('12345');
	}

	public function testEan13ThrowsExceptionForNonNumericInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('EAN-13 requires 12 or 13 digits');

		$this->barcodeGenerator->ean13('12345678901A');
	}

	public function testEan8ValidInput(): void
	{
		$result = $this->barcodeGenerator->ean8('1234567');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="EAN8"', $result);
		$this->assertStringContainsString('data-value="1234567"', $result);
	}

	public function testEan8ValidInputWith8Digits(): void
	{
		// Use a valid EAN-8 with correct check digit
		$result = $this->barcodeGenerator->ean8('12345670');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="EAN8"', $result);
		$this->assertStringContainsString('data-value="12345670"', $result);
	}

	public function testEan8ThrowsExceptionForInvalidInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('EAN-8 requires 7 or 8 digits');

		$this->barcodeGenerator->ean8('123');
	}

	public function testUpcaValidInput(): void
	{
		$result = $this->barcodeGenerator->upca('12345678901');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="UPCA"', $result);
		$this->assertStringContainsString('data-value="12345678901"', $result);
	}

	public function testUpcaValidInputWith12Digits(): void
	{
		$result = $this->barcodeGenerator->upca('123456789012');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="UPCA"', $result);
		$this->assertStringContainsString('data-value="123456789012"', $result);
	}

	public function testUpcaThrowsExceptionForInvalidInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('UPC-A requires 11 or 12 digits');

		$this->barcodeGenerator->upca('12345');
	}

	public function testUpceValidInput(): void
	{
		$result = $this->barcodeGenerator->upce('1234567');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="UPCE"', $result);
		$this->assertStringContainsString('data-value="1234567"', $result);
	}

	public function testUpceValidInputWith8Digits(): void
	{
		$result = $this->barcodeGenerator->upce('12345670');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="UPCE"', $result);
		$this->assertStringContainsString('data-value="12345670"', $result);
	}

	public function testUpceThrowsExceptionForInvalidInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('UPC-E requires 7 or 8 digits');

		$this->barcodeGenerator->upce('123');
	}

	public function testCode93GeneratesValidOutput(): void
	{
		$result = $this->barcodeGenerator->code93('TEST93');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="C93"', $result);
		$this->assertStringContainsString('data-value="TEST93"', $result);
	}

	public function testI25ValidNumericInput(): void
	{
		$result = $this->barcodeGenerator->i25('1234567890');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="I25"', $result);
		$this->assertStringContainsString('data-value="1234567890"', $result);
	}

	public function testI25ThrowsExceptionForNonNumericInput(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Interleaved 2 of 5 requires numeric data only');

		$this->barcodeGenerator->i25('ABC123');
	}

	public function testCodabarGeneratesValidOutput(): void
	{
		$result = $this->barcodeGenerator->codabar('A1234B');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="CODABAR"', $result);
		$this->assertStringContainsString('data-value="A1234B"', $result);
	}

	public function testCustomBarcodeWithValidType(): void
	{
		$result = $this->barcodeGenerator->custom('CUSTOM123', 'C128');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-type="C128"', $result);
		$this->assertStringContainsString('data-value="CUSTOM123"', $result);
	}

	public function testCustomBarcodeWithSvgFormat(): void
	{
		$result = $this->barcodeGenerator->custom('CUSTOM123', 'C39', ['format' => 'svg']);

		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringNotContainsString('<div class="barcode-container"', $result);
	}

	public function testCustomBarcodeWithInvalidTypeThrowsException(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unable to generate barcode with type: INVALID');

		$this->barcodeGenerator->custom('TEST', 'INVALID');
	}

	public function testGetSupportedTypesReturnsArray(): void
	{
		$types = $this->barcodeGenerator->getSupportedTypes();

		$this->assertIsArray($types);
		$this->assertNotEmpty($types);
		$this->assertContains('C128', $types);
		$this->assertContains('C39', $types);
		$this->assertContains('EAN13', $types);
		$this->assertContains('UPCA', $types);
	}

	public function testGetSupportedTypesContainsExpectedTypes(): void
	{
		$types         = $this->barcodeGenerator->getSupportedTypes();
		$expectedTypes = [
			'C128', 'C39', 'C93', 'EAN13', 'EAN8', 'UPCA', 'UPCE',
			'I25', 'CODABAR', 'CODE11', 'S25', 'POSTNET', 'PLANET',
			'RMS4CC', 'KIX', 'IMB',
		];

		foreach ($expectedTypes as $type) {
			$this->assertContains($type, $types);
		}
	}

	public function testDifferentFormatsGenerateDifferentOutput(): void
	{
		$htmlResult = $this->barcodeGenerator->code128('FORMAT_TEST', ['format' => 'html']);
		$svgResult  = $this->barcodeGenerator->code128('FORMAT_TEST', ['format' => 'svg']);

		$this->assertNotEquals($htmlResult, $svgResult);
		$this->assertStringContainsString('<div class="barcode-container"', $htmlResult);
		$this->assertStringNotContainsString('<div class="barcode-container"', $svgResult);
	}

	public function testHtmlOutputContainsProperHtmlStructure(): void
	{
		$result = $this->barcodeGenerator->code128('HTML_TEST');

		$this->assertStringContainsString('<div class="barcode-container"', $result);
		$this->assertStringContainsString('data-type="C128"', $result);
		$this->assertStringContainsString('data-value="HTML_TEST"', $result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
		$this->assertStringContainsString('</div>', $result);
	}

	public function testSvgOutputIsValidSvg(): void
	{
		$result = $this->barcodeGenerator->code128('SVG_TEST', ['format' => 'svg']);

		$this->assertStringStartsWith('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
		$this->assertStringNotContainsString('<?xml', $result);
	}

	public function testSameDataGeneratesSameOutput(): void
	{
		$result1 = $this->barcodeGenerator->code128('IDENTICAL');
		$result2 = $this->barcodeGenerator->code128('IDENTICAL');

		$this->assertEquals($result1, $result2);
	}

	public function testDifferentDataGeneratesDifferentOutput(): void
	{
		$result1 = $this->barcodeGenerator->code128('DATA_A');
		$result2 = $this->barcodeGenerator->code128('DATA_B');

		$this->assertNotEquals($result1, $result2);
	}

	public function testEdgeCaseMinimalValidString(): void
	{
		// Test with minimal valid content instead of empty string to avoid library warnings
		$result = $this->barcodeGenerator->code128('A');

		$this->assertIsString($result);
		$this->assertStringContainsString('data-value="A"', $result);
		$this->assertStringContainsString('<svg', $result);
	}

	public function testSpecialCharactersInData(): void
	{
		$result = $this->barcodeGenerator->code128('TEST<>&"\'');

		$this->assertIsString($result);
		// HTML should be escaped in data attributes
		$this->assertStringContainsString('data-value="TEST&lt;&gt;&amp;&quot;&#039;"', $result);
	}

	public function testCustomWidthAndHeight(): void
	{
		$result = $this->barcodeGenerator->code128('SIZE_TEST', [
			'width'  => 300,
			'height' => 100,
		]);

		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
	}

	public function testCustomColor(): void
	{
		$result = $this->barcodeGenerator->code128('COLOR_TEST', ['color' => 'blue']);

		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
	}

	public function testAllMainBarcodeTypesGenerate(): void
	{
		$testCases = [
			'code128' => ['method' => 'code128', 'data' => 'TEST128'],
			'code39'  => ['method' => 'code39', 'data' => 'TEST39'],
			'code93'  => ['method' => 'code93', 'data' => 'TEST93'],
			'ean13'   => ['method' => 'ean13', 'data' => '123456789012'],
			'ean8'    => ['method' => 'ean8', 'data' => '1234567'],
			'upca'    => ['method' => 'upca', 'data' => '12345678901'],
			'upce'    => ['method' => 'upce', 'data' => '1234567'],
			'i25'     => ['method' => 'i25', 'data' => '1234567890'],
			'codabar' => ['method' => 'codabar', 'data' => 'A1234B'],
		];

		foreach ($testCases as $name => $case) {
			$result = $this->barcodeGenerator->{$case['method']}($case['data']);

			$this->assertIsString($result, "Failed for barcode type: {$name}");
			$this->assertStringContainsString('<svg', $result, "Failed for barcode type: {$name}");
			$this->assertStringContainsString('</svg>', $result, "Failed for barcode type: {$name}");
		}
	}
}
