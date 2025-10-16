<?php

namespace Tests\Unit\Domain\Settings\Services;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsValidator;

final class SettingsSaverTest extends TestCase
{
	private SettingsSaver $saver;
	private SettingsFetcher $fetcher;
	private SettingsValidator $validator;
	private CacheManager $cacheManager;
	private string $tempFile;

	protected function setUp(): void
	{
		$this->fetcher      = $this->createMock(SettingsFetcher::class);
		$this->validator    = $this->createMock(SettingsValidator::class);
		$this->cacheManager = $this->createMock(CacheManager::class);

		$this->saver = new SettingsSaver(
			$this->fetcher,
			$this->validator,
			$this->cacheManager
		);

		// Create temporary file for testing file operations
		$this->tempFile = sys_get_temp_dir() . '/tcms-test-' . uniqid() . '.php';
		$_SERVER['DOCUMENT_ROOT'] = sys_get_temp_dir();
		// Set up a temp directory structure
		$tempDir = sys_get_temp_dir() . '/tcms-test-' . uniqid();
		mkdir($tempDir, 0755, true);
		$_SERVER['DOCUMENT_ROOT'] = $tempDir;
		$this->tempFile = $tempDir . '/tcms.php';
	}

	protected function tearDown(): void
	{
		// Clean up temp file
		if (file_exists($this->tempFile)) {
			unlink($this->tempFile);
		}
		// Clean up temp directory
		if (isset($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'])) {
			rmdir($_SERVER['DOCUMENT_ROOT']);
		}
	}

	public function testSaveSectionValidatesData(): void
	{
		$section = 'smtp';
		$sectionData = ['host' => 'smtp.example.com', 'port' => 587];
		$processedData = ['host' => 'smtp.example.com', 'port' => 587, 'secure' => 'tls'];

		$this->validator->expects($this->once())
			->method('processSection')
			->with($section, $sectionData)
			->willReturn($processedData);

		$this->fetcher->method('loadSettings')->willReturn([
			'smtp' => ['host' => 'old.example.com'],
		]);

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->saveSection($section, $sectionData);

		$this->assertFileExists($this->tempFile);
	}

	public function testSaveSectionMergesWithExistingSection(): void
	{
		$section = 'smtp';
		$existingSettings = [
			'smtp' => [
				'host' => 'old.example.com',
				'port' => 465,
				'username' => 'user@example.com',
			],
		];
		$newData = ['host' => 'new.example.com', 'port' => 587];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		$this->saver->saveSection($section, $newData);

		// Verify file was written
		$this->assertFileExists($this->tempFile);

		// Verify deep merge occurred
		$savedSettings = include $this->tempFile;
		$this->assertEquals('new.example.com', $savedSettings['smtp']['host']);
		$this->assertEquals(587, $savedSettings['smtp']['port']);
		$this->assertEquals('user@example.com', $savedSettings['smtp']['username']); // Preserved
	}

	public function testSaveSectionHandlesGeneralSectionSpecially(): void
	{
		$existingSettings = [
			'domain' => 'old.example.com',
			'timezone' => 'UTC',
		];
		$newData = ['domain' => 'new.example.com', 'debug' => true];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		$this->saver->saveSection('general', $newData);

		// Verify file was written
		$this->assertFileExists($this->tempFile);

		// Verify general settings are merged at top level
		$savedSettings = include $this->tempFile;
		$this->assertEquals('new.example.com', $savedSettings['domain']);
		$this->assertEquals('UTC', $savedSettings['timezone']); // Preserved
		$this->assertTrue($savedSettings['debug']);
	}

	public function testSaveSectionCreatesNewSection(): void
	{
		$existingSettings = ['domain' => 'example.com'];
		$newData = ['key1' => 'value1', 'key2' => 'value2'];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		$this->saver->saveSection('newsection', $newData);

		// Verify file was written
		$this->assertFileExists($this->tempFile);

		// Verify new section was created
		$savedSettings = include $this->tempFile;
		$this->assertArrayHasKey('newsection', $savedSettings);
		$this->assertEquals($newData, $savedSettings['newsection']);
	}

	public function testSaveSettingsWritesEntireArray(): void
	{
		$settings = [
			'domain' => 'example.com',
			'smtp' => ['host' => 'smtp.example.com'],
			'cache' => ['enabled' => true],
		];

		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		$this->saver->saveSettings($settings);

		// Verify file was written
		$this->assertFileExists($this->tempFile);

		// Verify exact settings were saved
		$savedSettings = include $this->tempFile;
		$this->assertEquals($settings, $savedSettings);
	}

	public function testDeleteSectionRemovesSection(): void
	{
		$existingSettings = [
			'domain' => 'example.com',
			'smtp' => ['host' => 'smtp.example.com'],
			'cache' => ['enabled' => true],
		];

		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		$this->saver->deleteSection('smtp');

		// Verify file was written
		$this->assertFileExists($this->tempFile);

		// Verify section was removed
		$savedSettings = include $this->tempFile;
		$this->assertArrayNotHasKey('smtp', $savedSettings);
		$this->assertArrayHasKey('domain', $savedSettings);
		$this->assertArrayHasKey('cache', $savedSettings);
	}

	public function testDeleteSectionHandlesNonExistentSection(): void
	{
		$existingSettings = [
			'domain' => 'example.com',
			'cache' => ['enabled' => true],
		];

		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		// Should not throw exception when deleting non-existent section
		$this->saver->deleteSection('nonexistent');

		// Verify file was written
		$this->assertFileExists($this->tempFile);
	}

	public function testClearsCacheAfterSaveSection(): void
	{
		$this->validator->method('processSection')->willReturn(['key' => 'value']);
		$this->fetcher->method('loadSettings')->willReturn([]);

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->saveSection('test', ['key' => 'value']);
	}

	public function testClearsCacheAfterSaveSettings(): void
	{
		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->saveSettings(['domain' => 'test.com']);
	}

	public function testClearsCacheAfterDeleteSection(): void
	{
		$this->fetcher->method('loadSettings')->willReturn(['test' => ['key' => 'value']]);

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->deleteSection('test');
	}

	public function testWritesValidPhpSyntax(): void
	{
		$settings = ['domain' => 'example.com', 'debug' => true];

		$this->saver->saveSettings($settings);

		// Verify file is valid PHP
		$this->assertFileExists($this->tempFile);
		$contents = file_get_contents($this->tempFile);
		$this->assertStringStartsWith('<?php', $contents);

		// Verify file can be included and returns correct data
		$loadedSettings = include $this->tempFile;
		$this->assertEquals($settings, $loadedSettings);
	}

	public function testWritesJsonWithPrettyPrint(): void
	{
		$settings = [
			'domain' => 'example.com',
			'smtp' => [
				'host' => 'smtp.example.com',
				'port' => 587,
			],
		];

		$this->saver->saveSettings($settings);

		$contents = file_get_contents($this->tempFile);

		// Verify JSON is pretty printed (has newlines and indentation)
		$this->assertStringContainsString("{\n", $contents);
		$this->assertStringContainsString('    "domain"', $contents);
	}

	public function testHandlesNestedArrayMerging(): void
	{
		$existingSettings = [
			'cache' => [
				'redis' => [
					'host' => 'localhost',
					'port' => 6379,
					'database' => 0,
				],
			],
		];

		$newData = [
			'redis' => [
				'host' => '127.0.0.1',
				'password' => 'secret',
			],
		];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->method('clearAllCaches');

		$this->saver->saveSection('cache', $newData);

		$savedSettings = include $this->tempFile;

		// Verify deep merge preserved existing keys
		$this->assertEquals('127.0.0.1', $savedSettings['cache']['redis']['host']);
		$this->assertEquals(6379, $savedSettings['cache']['redis']['port']); // Preserved
		$this->assertEquals(0, $savedSettings['cache']['redis']['database']); // Preserved
		$this->assertEquals('secret', $savedSettings['cache']['redis']['password']); // Added
	}

	public function testHandlesSpecialCharactersInSettings(): void
	{
		$settings = [
			'domain' => 'example.com/path',
			'message' => 'Hello "World" with \'quotes\'',
			'unicode' => '你好世界',
		];

		$this->saver->saveSettings($settings);

		$savedSettings = include $this->tempFile;

		$this->assertEquals($settings['domain'], $savedSettings['domain']);
		$this->assertEquals($settings['message'], $savedSettings['message']);
		$this->assertEquals($settings['unicode'], $savedSettings['unicode']);
	}

	public function testHandlesEmptySettings(): void
	{
		$this->saver->saveSettings([]);

		$this->assertFileExists($this->tempFile);

		$savedSettings = include $this->tempFile;
		$this->assertEquals([], $savedSettings);
	}
}
