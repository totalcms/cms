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
		$this->mockConfig     = $this->createTestConfig(['watermarkFontsDepot' => 'watermark-fonts']);

		$this->textWatermarkFactory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$this->mockConfig
		);
	}

	private function createTestConfig(array $imageworksSettings = []): Config
	{
		$settings = [
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => '/tmp',
			'tmpdir'     => '/tmp',
			'cache'      => [],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'timezone'   => 'UTC',
			'imageworks' => $imageworksSettings,
		];

		return new Config($settings);
	}

	public function testLoadFontFromDepotTtf(): void
	{
		// Mock font content
		$fontContent = 'fake-ttf-content';

		// Font name with TTF extension - should check directly
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
		$method     = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'Dorsa-Regular.ttf');

		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		$this->assertStringEndsWith('.ttf', $result);

		// Clean up
		unlink($result);
	}

	public function testLoadFontFromDepotOtf(): void
	{
		// Mock font content
		$fontContent = 'fake-otf-content';

		// Font name with OTF extension - should check directly
		$this->mockFilesystem
			->expects($this->once())
			->method('fileExists')
			->with('depot/watermark-fonts/depot/CustomFont.otf')
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/CustomFont.otf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'CustomFont.otf');

		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		$this->assertStringEndsWith('.otf', $result);

		// Clean up
		unlink($result);
	}

	public function testLoadFontFromDepotAutoDetectTtf(): void
	{
		// Mock font content
		$fontContent = 'fake-ttf-content';

		// Font name without extension - should try TTF first and find it (extension check + final check)
		$this->mockFilesystem
			->expects($this->exactly(2))
			->method('fileExists')
			->with('depot/watermark-fonts/depot/MyFont.ttf')
			->willReturn(true);

		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/MyFont.ttf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'MyFont');

		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		$this->assertStringEndsWith('.ttf', $result);

		// Clean up
		unlink($result);
	}

	public function testLoadFontFromDepotAutoDetectOtf(): void
	{
		// Mock font content
		$fontContent = 'fake-otf-content';

		// Font name without extension - TTF not found, but OTF found (try .ttf, try .otf, final check)
		$this->mockFilesystem
			->expects($this->exactly(3))
			->method('fileExists')
			->willReturnCallback(function ($path) {
				if ($path === 'depot/watermark-fonts/depot/ModernFont.ttf') {
					return false;
				}
				if ($path === 'depot/watermark-fonts/depot/ModernFont.otf') {
					return true;
				}

				return false;
			});

		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/ModernFont.otf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'ModernFont');

		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);
		$this->assertStringEndsWith('.otf', $result);

		// Clean up
		unlink($result);
	}

	public function testLoadFontFromDepotNotFound(): void
	{
		// Font not found in either format - tries .ttf, .otf, then final check
		$this->mockFilesystem
			->expects($this->exactly(3))
			->method('fileExists')
			->willReturnCallback(function ($path) {
				return str_contains($path, 'NonExistent') ? false : true;
			});

		$this->mockFilesystem
			->expects($this->never())
			->method('read');

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'NonExistent');

		$this->assertNull($result);
	}

	public function testLoadFontFromDepotCustomDepot(): void
	{
		// Test with custom depot configuration
		$customConfig = $this->createTestConfig(['watermarkFontsDepot' => 'custom-fonts']);

		$customFactory = new TextWatermarkFactory(
			$this->mockFilesystem,
			$customConfig
		);

		// Should use custom depot path - tries .ttf, .otf, then final check
		$this->mockFilesystem
			->expects($this->exactly(3))
			->method('fileExists')
			->willReturnCallback(function ($path) {
				return str_contains($path, 'custom-fonts') && str_contains($path, 'TestFont') ? false : true;
			});

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($customFactory);
		$method     = $reflection->getMethod('loadFontFromDepot');
		$method->setAccessible(true);

		$result = $method->invoke($customFactory, 'TestFont');

		$this->assertNull($result);
	}

	public function testGetFontPathWithDepotFont(): void
	{
		// Mock successful font loading from depot
		$fontContent = 'fake-ttf-content';

		$this->mockFilesystem
			->expects($this->exactly(2))
			->method('fileExists')
			->willReturnCallback(function ($path) {
				// Return true for both the extension check and final path check
				return str_contains($path, 'Dorsa.ttf');
			});

		$this->mockFilesystem
			->expects($this->once())
			->method('read')
			->with('depot/watermark-fonts/depot/Dorsa.ttf')
			->willReturn($fontContent);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('getFontPath');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'Dorsa');

		$this->assertNotNull($result);
		$this->assertStringContainsString(sys_get_temp_dir(), $result);

		// Clean up
		if ($result && file_exists($result)) {
			unlink($result);
		}
	}

	public function testGetFontPathFallbackToDefault(): void
	{
		// Font not found in depot, should fall back to default - tries .ttf, .otf, then final check
		$this->mockFilesystem
			->expects($this->exactly(3))
			->method('fileExists')
			->willReturn(false);

		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('getFontPath');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, 'NonExistent');

		$this->assertStringEndsWith('/resources/fonts/RobotoRegular.ttf', $result);
		$this->assertFileExists($result);
	}

	public function testGetFontPathNoFontRequested(): void
	{
		// Use reflection to call the private method
		$reflection = new \ReflectionClass($this->textWatermarkFactory);
		$method     = $reflection->getMethod('getFontPath');
		$method->setAccessible(true);

		$result = $method->invoke($this->textWatermarkFactory, null);

		$this->assertStringEndsWith('/resources/fonts/RobotoRegular.ttf', $result);
		$this->assertFileExists($result);
	}

	public function testClearOldCache(): void
	{
		// Mock file listing
		$files = [
			'text_watermark_abc123.png',
			'text_watermark_def456.png',
			'other_file.png',
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
