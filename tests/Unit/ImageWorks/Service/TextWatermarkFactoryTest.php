<?php

declare(strict_types=1);

namespace Tests\Unit\ImageWorks\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

class TextWatermarkFactoryTest extends TestCase
{
	private TextWatermarkFactory $factory;
	private \PHPUnit\Framework\MockObject\MockObject $mockFilesystem;
	private \PHPUnit\Framework\MockObject\MockObject $mockConfig;

	protected function setUp(): void
	{
		$this->mockFilesystem = $this->createMock(StorageAdapterInterface::class);
		$this->mockConfig = $this->createMock(Config::class);

		$this->factory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$this->mockConfig
		);
	}

	public function testConstantsAreDefined(): void
	{
		$this->assertEquals('.watermarks', TextWatermarkFactory::WATERMARK_DIR);
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
			'marktext' => 'Test Watermark',
			'marktextsize' => 100,
			'marktextcolor' => 'ffffff'
		];

		// Mock filesystem to indicate cached file exists
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->with($this->stringStartsWith('.watermarks/'))
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
			'marktext' => 'Test Watermark',
			'marktextsize' => 20,
			'marktextcolor' => 'ffffff'
		];

		// Mock filesystem to indicate no cached file exists
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->with($this->stringStartsWith('.watermarks/'))
			->willReturn(false);

		// Mock the file save operation
		$this->mockFilesystem->expects($this->once())
			->method('write')
			->with(
				$this->stringStartsWith('.watermarks/'),
				$this->isType('string')
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
			'marktext' => 'Custom Watermark',
			'marktextsize' => 30,
			'marktextcolor' => '000000',
			'marktextbg' => 'ffffff',
			'marktextpad' => 15,
			'marktextangle' => 45
		];

		// Mock filesystem for no cache initially
		$this->mockFilesystem->expects($this->once())
			->method('fileExists')
			->with($this->stringStartsWith('.watermarks/'))
			->willReturn(false);

		// Mock the file save operation
		$this->mockFilesystem->expects($this->once())
			->method('write')
			->with(
				$this->stringStartsWith('.watermarks/'),
				$this->isType('string')
			)
			->willReturn(true);

		$result = $this->factory->generateTextWatermark($params);

		$this->assertIsString($result);
		$this->assertStringEndsWith('.png', $result);
		$this->assertNotEmpty($result);
	}

	public function testParseColorWithValidHexColor(): void
	{
		// Test the color parsing functionality through reflection since it's private
		$reflection = new \ReflectionClass($this->factory);
		$parseColorMethod = $reflection->getMethod('parseColor');
		$parseColorMethod->setAccessible(true);

		$result = $parseColorMethod->invoke($this->factory, 'ff0000');

		$this->assertIsArray($result);
		$this->assertCount(3, $result);
		$this->assertEquals([255, 0, 0], $result); // Red color
	}

	public function testParseColorWithShortHexColor(): void
	{
		$reflection = new \ReflectionClass($this->factory);
		$parseColorMethod = $reflection->getMethod('parseColor');
		$parseColorMethod->setAccessible(true);

		$result = $parseColorMethod->invoke($this->factory, 'f00');

		$this->assertIsArray($result);
		$this->assertEquals([255, 0, 0], $result); // Red color expanded
	}

	public function testParseColorWithHashPrefix(): void
	{
		$reflection = new \ReflectionClass($this->factory);
		$parseColorMethod = $reflection->getMethod('parseColor');
		$parseColorMethod->setAccessible(true);

		$result = $parseColorMethod->invoke($this->factory, '#00ff00');

		$this->assertIsArray($result);
		$this->assertEquals([0, 255, 0], $result); // Green color
	}

	public function testParseColorWithInvalidColor(): void
	{
		$reflection = new \ReflectionClass($this->factory);
		$parseColorMethod = $reflection->getMethod('parseColor');
		$parseColorMethod->setAccessible(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid color format');

		$parseColorMethod->invoke($this->factory, 'invalid');
	}

	public function testGenerateCacheKey(): void
	{
		$reflection = new \ReflectionClass($this->factory);
		$generateCacheKeyMethod = $reflection->getMethod('generateCacheKey');
		$generateCacheKeyMethod->setAccessible(true);

		$text = 'Test Text';
		$fontSize = 100;
		$fontColor = [255, 255, 255];
		$fontFamily = 'Arial';
		$backgroundColor = [0, 0, 0];
		$padding = 10;
		$angle = 0;

		$result = $generateCacheKeyMethod->invoke(
			$this->factory,
			$text,
			$fontSize,
			$fontColor,
			$fontFamily,
			$backgroundColor,
			$padding,
			$angle
		);

		$this->assertIsString($result);
		$this->assertNotEmpty($result);
		// Hash should be consistent for same inputs
		$result2 = $generateCacheKeyMethod->invoke(
			$this->factory,
			$text,
			$fontSize,
			$fontColor,
			$fontFamily,
			$backgroundColor,
			$padding,
			$angle
		);
		$this->assertEquals($result, $result2);
	}

	public function testGenerateCacheKeyDifferentForDifferentInputs(): void
	{
		$reflection = new \ReflectionClass($this->factory);
		$generateCacheKeyMethod = $reflection->getMethod('generateCacheKey');
		$generateCacheKeyMethod->setAccessible(true);

		$baseParams = ['Test', 100, [255, 255, 255], 'Arial', [0, 0, 0], 10, 0];
		$modifiedParams = ['Different Text', 100, [255, 255, 255], 'Arial', [0, 0, 0], 10, 0];

		$result1 = $generateCacheKeyMethod->invokeArgs($this->factory, $baseParams);
		$result2 = $generateCacheKeyMethod->invokeArgs($this->factory, $modifiedParams);

		$this->assertNotEquals($result1, $result2);
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
			'marktext' => 'Custom Text',
			'marktextsize' => 200,
			'marktextcolor' => '00ff00',
			'marktextfont' => 'CustomFont',
			'marktextbg' => 'ff0000',
			'marktextpad' => 20,
			'marktextangle' => 45
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