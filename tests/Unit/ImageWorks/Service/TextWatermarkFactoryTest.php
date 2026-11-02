<?php

declare(strict_types=1);

namespace Tests\Unit\ImageWorks\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

class TextWatermarkFactoryTest extends TestCase
{
	private TextWatermarkFactory $factory;
	private MockObject $mockFilesystem;
	private MockObject $mockConfig;
	private MockObject $mockLoggerFactory;

	protected function setUp(): void
	{
		$this->mockFilesystem       = $this->createMock(StorageAdapterInterface::class);
		$this->mockConfig           = $this->createMock(Config::class);
		$this->mockLoggerFactory    = $this->createMock(LoggerFactory::class);

		$this->factory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$this->mockConfig,
			$this->mockLoggerFactory
		);
	}

	public function testConstantsAreDefined(): void
	{
		$this->assertEquals('.system/watermarks', TextWatermarkFactory::WATERMARK_DIR);
	}

	public function testGenerateTextWatermarkThrowsExceptionWhenTextEmpty(): void
	{
		$params = ['marktext' => ''];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Text watermark requires marktext parameter');

		$this->factory->generateTextWatermark($params);
	}

	public function testGenerateTextWatermarkThrowsExceptionWhenTextMissing(): void
	{
		$params = []; // No marktext parameter

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Text watermark requires marktext parameter');

		$this->factory->generateTextWatermark($params);
	}

	public function testGenerateTextWatermarkReturnsCachedFile(): void
	{
		$params = [
			'marktext'      => 'Test Watermark',
			'marktextsize'  => 100,
			'marktextcolor' => 'ffffff',
		];

		// Mock filesystem to indicate cached file exists
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->with($this->stringStartsWith('.system/watermarks/'))
			->willReturn(true);

		$result = $this->factory->generateTextWatermark($params);

		// Should return just the filename with .png extension
		$this->assertIsString($result);
		$this->assertStringEndsWith('.png', $result);
		$this->assertNotEmpty($result);
	}

	public function testGenerateTextWatermarkCreatesNewWatermarkWhenNotCached(): void
	{
		// Skip if GD extension is not available
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for watermark generation tests');
		}

		$params = [
			'marktext'      => 'Test Watermark',
			'marktextsize'  => 20,
			'marktextcolor' => 'ffffff',
		];

		// Mock filesystem to indicate no cached file exists
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->with($this->stringStartsWith('.system/watermarks/'))
			->willReturn(false);

		// Mock the file save operation
		$this->mockFilesystem->expects($this->once())
			->method('write')
			->with(
				$this->stringStartsWith('.system/watermarks/'),
				$this->isString()
			)
			->willReturn(true);

		$result = $this->factory->generateTextWatermark($params);

		// Should return a PNG filename
		$this->assertIsString($result);
		$this->assertStringEndsWith('.png', $result);
		$this->assertNotEmpty($result);
	}

	public function testGenerateTextWatermarkWithCustomFontAndBackground(): void
	{
		// Skip if GD extension is not available
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for watermark generation tests');
		}

		$params = [
			'marktext'      => 'Custom Watermark',
			'marktextsize'  => 30,
			'marktextcolor' => '000000',
			'marktextbg'    => 'ffffff',
			'marktextpad'   => 15,
			'marktextangle' => 45,
		];

		// Mock filesystem for no cache initially
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->with($this->stringStartsWith('.system/watermarks/'))
			->willReturn(false);

		// Mock the file save operation
		$this->mockFilesystem->expects($this->once())
			->method('write')
			->with(
				$this->stringStartsWith('.system/watermarks/'),
				$this->isString()
			)
			->willReturn(true);

		$result = $this->factory->generateTextWatermark($params);

		$this->assertIsString($result);
		$this->assertStringEndsWith('.png', $result);
		$this->assertNotEmpty($result);
	}

	public function testParseColorWithValidHexColor(): void
	{
		$this->assertEquals([255, 0, 0], TextWatermarkStyle::parseColor('ff0000'));
	}

	public function testParseColorWithShortHexColor(): void
	{
		$this->assertEquals([255, 0, 0], TextWatermarkStyle::parseColor('f00'));
	}

	public function testParseColorWithHashPrefix(): void
	{
		$this->assertEquals([0, 255, 0], TextWatermarkStyle::parseColor('#00ff00'));
	}

	public function testParseColorWithInvalidColor(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid color format');

		TextWatermarkStyle::parseColor('invalid');
	}

	public function testGenerateCacheKey(): void
	{
		$params = ['marktext' => 'Test Text', 'marktextsize' => 100, 'marktextfont' => 'Arial', 'marktextbg' => '000000'];

		$result = (new TextWatermarkStyle($params))->cacheKey();

		$this->assertNotEmpty($result);
		// Same style, same file
		$this->assertEquals($result, (new TextWatermarkStyle($params))->cacheKey());
	}

	public function testGenerateCacheKeyDifferentForDifferentInputs(): void
	{
		$base = ['marktext' => 'Test', 'marktextsize' => 100];

		$this->assertNotEquals((new TextWatermarkStyle($base))->cacheKey(), (new TextWatermarkStyle(['marktext' => 'Different Text'] + $base))->cacheKey());
		// Outline, shadow and tiling are part of the style too
		$this->assertNotEquals((new TextWatermarkStyle($base))->cacheKey(), (new TextWatermarkStyle($base + ['marktextstroke' => 5]))->cacheKey());
		$this->assertNotEquals((new TextWatermarkStyle($base))->cacheKey(), (new TextWatermarkStyle($base + ['marktextshadow' => 5]))->cacheKey());
		$this->assertNotEquals((new TextWatermarkStyle($base))->cacheKey(), (new TextWatermarkStyle($base + ['marktextpos' => 'tile']))->cacheKey());
	}

	public function testTiledStyleDefaults(): void
	{
		$tiled = new TextWatermarkStyle(['marktext' => 'Proof', 'marktextpos' => 'tile']);
		$plain = new TextWatermarkStyle(['marktext' => 'Proof']);

		$this->assertSame(TextWatermarkStyle::DEFAULT_TILES, $tiled->tiles);
		$this->assertSame(TextWatermarkStyle::DEFAULT_TILE_ANGLE, $tiled->angle);
		$this->assertSame(0, $plain->tiles);
		$this->assertSame(0, $plain->angle);
		$this->assertSame(12, (new TextWatermarkStyle(['marktext' => 'P', 'marktextpos' => 'tile', 'marktextrepeat' => 99]))->tiles);
	}

	public function testStrokeAndShadowArePercentagesOfTheFontSize(): void
	{
		$style = new TextWatermarkStyle(['marktext' => 'P', 'marktextsize' => 200, 'marktextstroke' => 5, 'marktextshadow' => 50]);

		$this->assertSame(10, $style->stroke);
		// capped at 25%
		$this->assertSame(50, $style->shadow);
	}

	public function testDefaultParameterValues(): void
	{
		$params = [
			'marktext' => 'Test',
		];

		// Mock filesystem to return cached file to avoid GD operations
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->willReturn(true);

		$result = $this->factory->generateTextWatermark($params);

		// Should not throw exception and should return a filename
		$this->assertIsString($result);
		$this->assertNotEmpty($result);
		$this->assertStringEndsWith('.png', $result);
	}

	public function testCustomParameterValues(): void
	{
		$params = [
			'marktext'      => 'Custom Text',
			'marktextsize'  => 200,
			'marktextcolor' => '00ff00',
			'marktextfont'  => 'CustomFont',
			'marktextbg'    => 'ff0000',
			'marktextpad'   => 20,
			'marktextangle' => 45,
		];

		// Mock filesystem to return cached file
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->willReturn(true);

		$result = $this->factory->generateTextWatermark($params);

		$this->assertIsString($result);
		$this->assertNotEmpty($result);
		$this->assertStringEndsWith('.png', $result);
	}

	public function testCacheDirectoryConstant(): void
	{
		// Verify the cache directory constant is used correctly
		$this->assertStringStartsWith('.', TextWatermarkFactory::WATERMARK_DIR);
		$this->assertStringContainsString('watermark', strtolower(TextWatermarkFactory::WATERMARK_DIR));
	}
}
