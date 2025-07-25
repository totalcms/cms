<?php

namespace Tests\Unit\ImageWorks;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

/**
 * Tests for TextWatermarkFactory depot font loading and configuration.
 */
class TextWatermarkFactoryTest extends TestCase
{
	private TextWatermarkFactory $textWatermarkFactory;
	private StorageAdapterInterface $mockFilesystem;
	private Config $mockConfig;

	protected function setUp(): void
	{
		$this->mockFilesystem = $this->createMock(StorageAdapterInterface::class);
		$this->mockConfig = $this->createTestConfig(['watermarkFontsDepot' => 'watermark-fonts']);

		$this->textWatermarkFactory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$this->mockConfig
		);
	}

	private function createTestConfig(array $imageworksSettings = []): Config
	{
		$settings = [
			'env' => 'test',
			'template' => '/tmp',
			'dashboard' => [],
			'datadir' => '/tmp',
			'tmpdir' => '/tmp',
			'cache' => [],
			'logger' => [],
			'sentry' => [],
			'error' => [],
			'domain' => 'test.com',
			'api' => 'http://test.com/api',
			'locale' => 'en_US',
			'session' => [],
			'auth' => [],
			'debug' => false,
			'notfound' => '/404',
			'htmlclean' => [],
			'timezone' => 'UTC',
			'imageworks' => $imageworksSettings
		];
		return new Config($settings);
	}

	public function testLoadFontFromDepotWithoutExtension(): void
	{
		// Mock font content
		$fontContent = 'fake-ttf-content';
		
		// Expect the correct depot path to be checked
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/Dorsa-Regular.ttf')
			->willReturn(true);

		// Expect the font to be read
		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/Dorsa-Regular.ttf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'Dorsa-Regular');

		// Should return a temporary file path
		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		$this->assertStringContainsString('watermark_font_Dorsa-Regular_', $result);
		$this->assertStringEndsWith('.ttf', $result);
		
		// Verify the temporary file was created with correct content
		$this->assertFileExists($result);
		$this->assertEquals($fontContent, file_get_contents($result));
		
		// Clean up
		unlink($result);
	}

	public function testLoadFontFromDepotWithExtension(): void
	{
		// Mock font content
		$fontContent = 'fake-ttf-content';
		
		// Expect the correct depot path to be checked (should still work with .ttf extension)
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/Dorsa-Regular.ttf')
			->willReturn(true);

		// Expect the font to be read
		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/Dorsa-Regular.ttf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'Dorsa-Regular.ttf');

		// Should return a temporary file path
		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		
		// Clean up
		unlink($result);
	}

	public function testLoadFontFromDepotFileNotFound(): void
	{
		// Expect the depot path to be checked but file doesn't exist
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/NonExistent.ttf')
			->willReturn(false);

		// Should not attempt to read the file
		$this->mockFilesystem
			->expects($this->never())
			->method('read');

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'NonExistent');

		// Should return null when font not found
		$this->assertNull($result);
	}

	public function testLoadFontFromDepotWithCustomDepotId(): void
	{
		// Create a new config with custom depot ID
		$customConfig = $this->createTestConfig([
			'watermarkFontsDepot' => 'custom-fonts'
		]);
		
		$customFactory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$customConfig
		);

		// Expect the custom depot path to be checked
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/custom-fonts/depot/MyFont.ttf')
			->willReturn(false);

		// Use reflection to call the private method on the custom factory
		$reflection = new \ReflectionClass($customFactory);
		$method = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($customFactory, 'MyFont');

		$this->assertNull($result);
	}

	public function testLoadFontFromDepotFallbackToDefault(): void
	{
		// Create config with no watermarkFontsDepot specified
		$defaultConfig = $this->createTestConfig([]); // No watermarkFontsDepot specified
		
		$defaultFactory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$defaultConfig
		);

		// Expect fallback to default depot name
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/TestFont.ttf')
			->willReturn(false);

		// Use reflection to call the private method on the default factory
		$reflection = new \ReflectionClass($defaultFactory);
		$method = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($defaultFactory, 'TestFont');

		$this->assertNull($result);
	}

	public function testGetFontPathWithDepotFont(): void
	{
		// Mock font content
		$fontContent = 'fake-ttf-content';
		
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/Dorsa-Regular.ttf')
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/Dorsa-Regular.ttf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method = $reflection->getMethod('getFontPath');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'Dorsa-Regular');

		// Should return depot font path (temporary file)
		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		
		// Clean up
		if ($result && file_exists($result)) {
			unlink($result);
		}
	}

	public function testGetFontPathFallbackToDefault(): void
	{
		// Mock depot font not found
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/NonExistent.ttf')
			->willReturn(false);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method = $reflection->getMethod('getFontPath');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'NonExistent');

		// Should return default font path
		$this->assertStringEndsWith('/resources/fonts/RobotoRegular.ttf', $result);
		$this->assertFileExists($result);
	}

	public function testGetFontPathNoFontRequested(): void
	{
		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method = $reflection->getMethod('getFontPath');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, null);

		// Should return default font path when no specific font requested
		$this->assertStringEndsWith('/resources/fonts/RobotoRegular.ttf', $result);
		$this->assertFileExists($result);
	}

	public function testClearOldCache(): void
	{
		// Mock file listing
		$files = [
			'text_watermark_abc123.png',
			'text_watermark_def456.png',
			'other_file.png'
		];

		$this->mockFilesystem
			->expects($this->once())
			->method('listFiles')
			->with('.watermarks')
			->willReturn($files);

		// Mock flysystem for lastModified calls
		$mockFlysystem = $this->createMock(\League\Flysystem\FilesystemOperator::class);
		$this->mockFilesystem
			->expects($this->exactly(2)) // Only called for text_watermark_ files
			->method('flysystem')
			->willReturn($mockFlysystem);

		// Mock old timestamps that should be deleted
		$oldTimestamp = time() - 3600; // 1 hour ago
		$mockFlysystem
			->expects($this->exactly(2))
			->method('lastModified')
			->willReturn($oldTimestamp);

		// Expect delete calls for old watermark files
		$this->mockFilesystem
			->expects($this->exactly(2))
			->method('delete')
			->with($this->logicalOr(
				'.watermarks/text_watermark_abc123.png',
				'.watermarks/text_watermark_def456.png'
			));

		$result = $this->textWatermarkFactory->clearOldCache(1800); // 30 minutes

		$this->assertEquals(2, $result);
	}
}