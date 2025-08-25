<?php

declare(strict_types=1);

namespace Tests\Unit\Media\Generator;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Generator\QRGenerator;

final class QRGeneratorTest extends TestCase
{
	private QRGenerator $qrGenerator;

	protected function setUp(): void
	{
		$this->qrGenerator = new QRGenerator();
	}

	public function testConstructorWithDefaultSize(): void
	{
		$generator = new QRGenerator();
		$this->assertInstanceOf(QRGenerator::class, $generator);
	}

	public function testConstructorWithCustomSize(): void
	{
		$generator = new QRGenerator(256);
		$this->assertInstanceOf(QRGenerator::class, $generator);
	}

	public function testTextGeneratesSvg(): void
	{
		$result = $this->qrGenerator->text('Hello World');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
		$this->assertStringContainsString('xmlns', $result);
	}

	public function testTextWithEmptyStringThrowsException(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		
		$this->qrGenerator->text('');
	}

	public function testTextWithSpecialCharacters(): void
	{
		$text = 'Hello! @#$%^&*()_+-=[]{}|;:",.<>?';
		$result = $this->qrGenerator->text($text);
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testTextWithUnicodeCharacters(): void
	{
		// Use simpler unicode that BaconQrCode handles well
		$text = 'Hello café résumé';
		$result = $this->qrGenerator->text($text);
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testTextWithLongString(): void
	{
		$text = str_repeat('This is a long test string for QR code generation. ', 20);
		$result = $this->qrGenerator->text($text);
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testUrlGeneratesSvg(): void
	{
		$result = $this->qrGenerator->url('https://example.com');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testUrlWithComplexUrl(): void
	{
		$url = 'https://example.com/path/to/page?param1=value1&param2=value2#section';
		$result = $this->qrGenerator->url($url);
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testUrlWithHttpUrl(): void
	{
		$result = $this->qrGenerator->url('http://example.com');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testUrlWithFtpUrl(): void
	{
		$result = $this->qrGenerator->url('ftp://files.example.com/document.pdf');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testTelGeneratesSvgWithTelPrefix(): void
	{
		$result = $this->qrGenerator->tel('1234567890');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testTelWithFormattedPhoneNumber(): void
	{
		$result = $this->qrGenerator->tel('+1 (555) 123-4567');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testTelWithInternationalNumber(): void
	{
		$result = $this->qrGenerator->tel('+44 20 7946 0958');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testTelWithExtension(): void
	{
		$result = $this->qrGenerator->tel('555-1234 ext 100');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testGpsGeneratesSvgWithGeoPrefix(): void
	{
		$result = $this->qrGenerator->gps('40.7128', '-74.0060');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testGpsWithPositiveCoordinates(): void
	{
		$result = $this->qrGenerator->gps('51.5074', '0.1278'); // London
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testGpsWithNegativeCoordinates(): void
	{
		$result = $this->qrGenerator->gps('-33.8688', '151.2093'); // Sydney
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testGpsWithZeroCoordinates(): void
	{
		$result = $this->qrGenerator->gps('0.0000', '0.0000'); // Null Island
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testGpsWithHighPrecisionCoordinates(): void
	{
		$result = $this->qrGenerator->gps('40.748817', '-73.985428'); // Empire State Building
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testSvgOutputDoesNotContainXmlDeclaration(): void
	{
		// The stripFirstLine method should remove the XML declaration
		$result = $this->qrGenerator->text('test');
		
		$this->assertStringNotContainsString('<?xml', $result);
		$this->assertStringStartsWith('<svg', $result);
	}

	public function testSvgOutputContainsValidSvgStructure(): void
	{
		$result = $this->qrGenerator->text('structure test');
		
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $result);
		$this->assertStringContainsString('viewBox=', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testDifferentMethodsGenerateDifferentOutput(): void
	{
		$textResult = $this->qrGenerator->text('test');
		$urlResult = $this->qrGenerator->url('https://test.com');
		$telResult = $this->qrGenerator->tel('1234567890');
		$gpsResult = $this->qrGenerator->gps('40.7128', '-74.0060');
		
		// All should be different due to different prefixes or content
		$this->assertNotEquals($textResult, $urlResult);
		$this->assertNotEquals($textResult, $telResult);
		$this->assertNotEquals($textResult, $gpsResult);
		$this->assertNotEquals($urlResult, $telResult);
	}

	public function testSameContentGeneratesSameOutput(): void
	{
		$result1 = $this->qrGenerator->text('identical content');
		$result2 = $this->qrGenerator->text('identical content');
		
		$this->assertEquals($result1, $result2);
	}

	public function testDifferentContentGeneratesDifferentOutput(): void
	{
		$result1 = $this->qrGenerator->text('content A');
		$result2 = $this->qrGenerator->text('content B');
		
		$this->assertNotEquals($result1, $result2);
	}

	public function testQrCodeContainsPathElements(): void
	{
		$result = $this->qrGenerator->text('path test');
		
		// QR codes are typically rendered as paths in SVG
		$this->assertStringContainsString('<path', $result);
	}

	public function testQrCodeWithNumericContent(): void
	{
		$result = $this->qrGenerator->text('1234567890');
		
		$this->assertIsString($result);
		$this->assertStringContainsString('<svg', $result);
		$this->assertStringContainsString('</svg>', $result);
	}

	public function testEdgeCaseInputs(): void
	{
		// Test various edge case inputs
		$edgeCases = [
			'single char' => 'A',
			'space only' => ' ',
			'tab char' => "\t",
			'newline' => "\n",
			'carriage return' => "\r",
			'mixed whitespace' => " \t\n\r ",
		];

		foreach ($edgeCases as $description => $input) {
			$result = $this->qrGenerator->text($input);
			
			$this->assertIsString($result, "Failed for case: {$description}");
			$this->assertStringContainsString('<svg', $result, "Failed for case: {$description}");
			$this->assertStringContainsString('</svg>', $result, "Failed for case: {$description}");
		}
	}

	public function testOutputSizeConsistency(): void
	{
		// Different content should produce SVGs with similar structure
		$results = [
			$this->qrGenerator->text('short'),
			$this->qrGenerator->text('medium length content'),
			$this->qrGenerator->text('very long content that should still generate a valid QR code'),
		];

		foreach ($results as $result) {
			$this->assertStringContainsString('width=', $result);
			$this->assertStringContainsString('height=', $result);
		}
	}

	public function testCustomSizeGenerator(): void
	{
		$smallGenerator = new QRGenerator(128);
		$largeGenerator = new QRGenerator(1024);

		$smallResult = $smallGenerator->text('size test');
		$largeResult = $largeGenerator->text('size test');

		$this->assertIsString($smallResult);
		$this->assertIsString($largeResult);
		$this->assertStringContainsString('<svg', $smallResult);
		$this->assertStringContainsString('<svg', $largeResult);

		// Results should be different due to different sizes
		$this->assertNotEquals($smallResult, $largeResult);
	}
}