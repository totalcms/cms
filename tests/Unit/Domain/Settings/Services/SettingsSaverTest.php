<?php

namespace Tests\Unit\Domain\Settings\Services;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsValidator;

final class SettingsSaverTest extends TestCase
{
	private SettingsSaver $saver;
	private \PHPUnit\Framework\MockObject\MockObject $fetcher;
	private \PHPUnit\Framework\MockObject\MockObject $validator;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;
	private \PHPUnit\Framework\MockObject\MockObject $settingsRepository;

	protected function setUp(): void
	{
		$this->fetcher             = $this->createMock(SettingsFetcher::class);
		$this->validator           = $this->createMock(SettingsValidator::class);
		$this->cacheManager        = $this->createMock(CacheManager::class);
		$this->settingsRepository  = $this->createMock(SettingsRepository::class);

		$this->saver = new SettingsSaver(
			$this->fetcher,
			$this->validator,
			$this->cacheManager,
			$this->settingsRepository
		);
	}

	public function testSaveSectionValidatesData(): void
	{
		$section       = 'smtp';
		$sectionData   = ['host' => 'smtp.example.com', 'port' => 587];
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

		$this->settingsRepository->expects($this->once())
			->method('save');

		$this->saver->saveSection($section, $sectionData);
	}

	public function testSaveSectionMergesWithExistingSection(): void
	{
		$section          = 'smtp';
		$existingSettings = [
			'smtp' => [
				'host'     => 'old.example.com',
				'port'     => 465,
				'username' => 'user@example.com',
			],
		];
		$newData = ['host' => 'new.example.com', 'port' => 587];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		// Capture the merged settings that will be saved
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with($this->callback(fn ($settings): bool =>
				// Verify deep merge occurred
				$settings['smtp']['host'] === 'new.example.com'
					   && $settings['smtp']['port'] === 587
					   && $settings['smtp']['username'] === 'user@example.com'));

		$this->saver->saveSection($section, $newData);
	}

	public function testSaveSectionHandlesGeneralSectionSpecially(): void
	{
		$existingSettings = [
			'sentry'   => 'old-key',
			'timezone' => 'UTC',
		];
		$newData = ['sentry' => 'new-key', 'notfound' => '/404'];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		// Verify general settings are merged at top level
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with($this->callback(fn ($settings): bool => $settings['sentry'] === 'new-key'
					   && $settings['timezone'] === 'UTC'
					   && $settings['notfound'] === '/404'));

		$this->saver->saveSection('general', $newData);
	}

	public function testSaveSectionCreatesNewSection(): void
	{
		$existingSettings = ['sentry' => 'test'];
		$newData          = ['key1' => 'value1', 'key2' => 'value2'];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		// Verify new section was created
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with($this->callback(fn ($settings): bool => isset($settings['newsection'])
					   && $settings['newsection'] === $newData));

		$this->saver->saveSection('newsection', $newData);
	}

	public function testSaveSettingsWritesEntireArray(): void
	{
		$settings = [
			'sentry' => 'test',
			'smtp'   => ['host' => 'smtp.example.com'],
			'cache'  => ['enabled' => true],
		];

		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		// Verify exact settings were saved
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with($settings);

		$this->saver->saveSettings($settings);
	}

	public function testDeleteSectionRemovesSection(): void
	{
		$existingSettings = [
			'sentry' => 'test',
			'smtp'   => ['host' => 'smtp.example.com'],
			'cache'  => ['enabled' => true],
		];

		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');

		// Verify section was removed
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with($this->callback(fn ($settings): bool => !isset($settings['smtp'])
					   && isset($settings['sentry'])
					   && isset($settings['cache'])));

		$this->saver->deleteSection('smtp');
	}

	public function testDeleteSectionHandlesNonExistentSection(): void
	{
		$existingSettings = [
			'sentry' => 'test',
			'cache'  => ['enabled' => true],
		];

		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->expects($this->once())->method('clearAllCaches');
		$this->settingsRepository->expects($this->once())->method('save');

		// Should not throw exception when deleting non-existent section
		$this->saver->deleteSection('nonexistent');
	}

	public function testClearsCacheAfterSaveSection(): void
	{
		$this->validator->method('processSection')->willReturn(['key' => 'value']);
		$this->fetcher->method('loadSettings')->willReturn([]);
		$this->settingsRepository->method('save');

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->saveSection('test', ['key' => 'value']);
	}

	public function testClearsCacheAfterSaveSettings(): void
	{
		$this->settingsRepository->method('save');

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->saveSettings(['sentry' => 'test.com']);
	}

	public function testClearsCacheAfterDeleteSection(): void
	{
		$this->fetcher->method('loadSettings')->willReturn(['test' => ['key' => 'value']]);
		$this->settingsRepository->method('save');

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches');

		$this->saver->deleteSection('test');
	}

	public function testHandlesNestedArrayMerging(): void
	{
		$existingSettings = [
			'cache' => [
				'redis' => [
					'host'     => 'localhost',
					'port'     => 6379,
					'database' => 0,
				],
			],
		];

		$newData = [
			'redis' => [
				'host'     => '127.0.0.1',
				'password' => 'secret',
			],
		];

		$this->validator->method('processSection')->willReturn($newData);
		$this->fetcher->method('loadSettings')->willReturn($existingSettings);
		$this->cacheManager->method('clearAllCaches');

		// Verify deep merge preserved existing keys
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with($this->callback(fn ($settings): bool => $settings['cache']['redis']['host'] === '127.0.0.1'
					   && $settings['cache']['redis']['port'] === 6379
					   && $settings['cache']['redis']['database'] === 0
					   && $settings['cache']['redis']['password'] === 'secret'));

		$this->saver->saveSection('cache', $newData);
	}

	public function testHandlesEmptySettings(): void
	{
		$this->settingsRepository->expects($this->once())
			->method('save')
			->with([]);

		$this->saver->saveSettings([]);
	}

	public function testDeepMergeArraysStaticMethod(): void
	{
		$array1 = [
			'a' => 'value1',
			'b' => ['b1' => 'value2', 'b2' => 'value3'],
		];

		$array2 = [
			'b' => ['b2' => 'overridden', 'b3' => 'value4'],
			'c' => 'value5',
		];

		$result = SettingsSaver::deepMergeArrays($array1, $array2);

		$this->assertEquals('value1', $result['a']);
		$this->assertEquals('value2', $result['b']['b1']);
		$this->assertEquals('overridden', $result['b']['b2']);
		$this->assertEquals('value4', $result['b']['b3']);
		$this->assertEquals('value5', $result['c']);
	}
}
