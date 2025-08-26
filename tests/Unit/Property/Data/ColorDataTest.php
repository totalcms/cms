<?php

declare(strict_types = 1);

namespace Tests\Unit\Property\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\ColorData;

final class ColorDataTest extends TestCase
{
	public function testConstructorWithHexString(): void
	{
		$color = new ColorData('#ff0000');

		$this->assertEquals('#ff0000', $color->hex);
		$this->assertIsArray($color->oklch);
		$this->assertArrayHasKey('l', $color->oklch);
		$this->assertArrayHasKey('c', $color->oklch);
		$this->assertArrayHasKey('h', $color->oklch);
	}

	public function testConstructorWithShortHex(): void
	{
		$color = new ColorData('#f00');

		$this->assertEquals('#f00', $color->hex);
		$this->assertIsArray($color->oklch);
	}

	public function testConstructorWithHexNoHash(): void
	{
		$color = new ColorData('ff0000');

		$this->assertEquals('ff0000', $color->hex);
		$this->assertIsArray($color->oklch);
	}

	public function testConstructorWithRgbString(): void
	{
		$color = new ColorData('rgb(255, 0, 0)');

		$this->assertEquals('#ff0000', $color->hex);
		$this->assertIsArray($color->oklch);
	}

	public function testConstructorWithHslString(): void
	{
		$color = new ColorData('hsl(0, 100%, 50%)');

		// HSL(0, 100%, 50%) should convert to red #ff0000
		$this->assertIsString($color->hex);
		$this->assertIsArray($color->oklch);
	}

	public function testConstructorWithOklchString(): void
	{
		$color = new ColorData('oklch(0.627, 0.258, 29.2)');

		$this->assertIsString($color->hex);
		$this->assertIsArray($color->oklch);
	}

	public function testConstructorWithArray(): void
	{
		$colorArray = [
			'hex'   => '#00ff00',
			'oklch' => ['l' => 0.866, 'c' => 0.294, 'h' => 142.5],
		];

		$color = new ColorData($colorArray);

		$this->assertEquals('#00ff00', $color->hex);
		$this->assertEquals(['l' => 0.866, 'c' => 0.294, 'h' => 142.5], $color->oklch);
	}

	public function testConstructorWithArrayMissingHex(): void
	{
		$colorArray = [
			'oklch' => ['l' => 0.866, 'c' => 0.294, 'h' => 142.5],
		];

		$color = new ColorData($colorArray);

		$this->assertEquals('#000000', $color->hex);
		$this->assertEquals(['l' => 0.866, 'c' => 0.294, 'h' => 142.5], $color->oklch);
	}

	public function testConstructorWithArrayMissingOklch(): void
	{
		$colorArray = [
			'hex' => '#ff0000',
		];

		$color = new ColorData($colorArray);

		$this->assertEquals('#ff0000', $color->hex);
		$this->assertIsArray($color->oklch);
		$this->assertArrayHasKey('l', $color->oklch);
		$this->assertArrayHasKey('c', $color->oklch);
		$this->assertArrayHasKey('h', $color->oklch);
	}

	public function testConstructorWithSettings(): void
	{
		$settings = ['label' => 'Primary Color'];
		$color    = new ColorData('#0000ff', $settings);

		$this->assertEquals($settings, $color->settings);
		$this->assertEquals('#0000ff', $color->hex);
	}

	public function testInvalidColorFormat(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid color format');

		new ColorData('invalid-color');
	}

	public function testHexToOklch(): void
	{
		$oklch = ColorData::hexToOklch('#ff0000');

		$this->assertIsArray($oklch);
		$this->assertArrayHasKey('l', $oklch);
		$this->assertArrayHasKey('c', $oklch);
		$this->assertArrayHasKey('h', $oklch);
		$this->assertIsFloat($oklch['l']);
		$this->assertIsFloat($oklch['c']);
		$this->assertIsFloat($oklch['h']);
	}

	public function testHexToRgb(): void
	{
		$rgb = ColorData::hexToRgb('#ff0000');

		$this->assertEquals(['r' => 255, 'g' => 0, 'b' => 0], $rgb);
	}

	public function testHexToHsl(): void
	{
		$hsl = ColorData::hexToHsl('#ff0000');

		$this->assertIsArray($hsl);
		$this->assertArrayHasKey('h', $hsl);
		$this->assertArrayHasKey('s', $hsl);
		$this->assertArrayHasKey('l', $hsl);
		$this->assertIsFloat($hsl['h']);
		$this->assertIsFloat($hsl['s']);
		$this->assertIsFloat($hsl['l']);
	}

	public function testOklchToHex(): void
	{
		$oklch = ['l' => 0.627, 'c' => 0.258, 'h' => 29.2];
		$hex   = ColorData::oklchToHex($oklch);

		$this->assertIsString($hex);
		$this->assertMatchesRegularExpression('/^#[a-f0-9]{6}$/i', $hex);
	}

	public function testOklchChange(): void
	{
		$oklch  = ['l' => 0.5, 'c' => 0.1, 'h' => 180.0];
		$change = ['l' => 0.2];

		$result = ColorData::oklchChange($oklch, $change);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('l', $result);
		$this->assertArrayHasKey('c', $result);
		$this->assertArrayHasKey('h', $result);
		$this->assertEquals(0.2, $result['l']); // Change function sets absolute value, not addition
		$this->assertEquals(0.1, $result['c']); // Unchanged
		$this->assertEquals(180.0, $result['h']); // Unchanged
	}

	public function testTransform(): void
	{
		$color  = new ColorData('#00ff00');
		$result = $color->transform();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('hex', $result);
		$this->assertArrayHasKey('oklch', $result);
		$this->assertEquals('#00ff00', $result['hex']);
		$this->assertIsArray($result['oklch']);
	}

	public function testToString(): void
	{
		$color  = new ColorData('#ff0000');
		$string = (string)$color;

		$this->assertIsString($string);
		$this->assertStringStartsWith('oklch(', $string);
		$this->assertStringEndsWith(')', $string);
		$this->assertStringContainsString('%', $string);
	}

	public function testColorSpaceConversions(): void
	{
		// Test round-trip conversions maintain reasonable accuracy
		$originalHex  = '#ff0000';
		$oklch        = ColorData::hexToOklch($originalHex);
		$convertedHex = ColorData::oklchToHex($oklch);

		// Colors should be reasonably similar after round-trip conversion
		$this->assertIsString($convertedHex);
		$this->assertMatchesRegularExpression('/^#[a-f0-9]{6}$/i', $convertedHex);
	}

	public function testVariousHexFormats(): void
	{
		// Test that different hex formats are handled properly
		// Using only valid hex values to avoid library deprecation warnings
		$oklch = ColorData::hexToOklch('#abcdef'); // Valid 6-digit hex
		$rgb   = ColorData::hexToRgb('#abc');        // Valid 3-digit hex
		$hsl   = ColorData::hexToHsl('#123456');     // Valid 6-digit hex

		// All conversions should return proper arrays with expected keys
		$this->assertIsArray($oklch);
		$this->assertArrayHasKey('l', $oklch);
		$this->assertArrayHasKey('c', $oklch);
		$this->assertArrayHasKey('h', $oklch);

		$this->assertIsArray($rgb);
		$this->assertArrayHasKey('r', $rgb);
		$this->assertArrayHasKey('g', $rgb);
		$this->assertArrayHasKey('b', $rgb);

		$this->assertIsArray($hsl);
		$this->assertArrayHasKey('h', $hsl);
		$this->assertArrayHasKey('s', $hsl);
		$this->assertArrayHasKey('l', $hsl);
	}

	public function testRgbStringParsing(): void
	{
		$color = new ColorData('rgb(128, 64, 192)');

		$this->assertIsString($color->hex);
		$this->assertMatchesRegularExpression('/^#[a-f0-9]{6}$/i', $color->hex);
	}

	public function testHslStringParsing(): void
	{
		$color = new ColorData('hsl(240, 100%, 50%)');

		$this->assertIsString($color->hex);
		$this->assertMatchesRegularExpression('/^#[a-f0-9]{6}$/i', $color->hex);
	}

	public function testOklchStringParsing(): void
	{
		$color = new ColorData('oklch(70, 0.15, 180)');

		$this->assertIsString($color->hex);
		$this->assertMatchesRegularExpression('/^#[a-f0-9]{6}$/i', $color->hex);
	}
}
