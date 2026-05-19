<?php

namespace Tests\Unit\Domain\Settings\Services;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Settings\Repository\InstallationRepository;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;

final class SettingsFetcherTest extends TestCase
{
	private SettingsFetcher $fetcher;
	private \PHPUnit\Framework\MockObject\MockObject $settingsRepository;
	private \PHPUnit\Framework\MockObject\MockObject $installationRepository;

	protected function setUp(): void
	{
		$this->settingsRepository     = $this->createMock(SettingsRepository::class);
		$this->installationRepository = $this->createMock(InstallationRepository::class);

		$this->fetcher = new SettingsFetcher(
			$this->settingsRepository,
			$this->installationRepository
		);
	}

	public function testLoadSettingsReturnsEmptyArrayWhenFileNotExists(): void
	{
		$this->settingsRepository->method('load')->willReturn([]);

		$result = $this->fetcher->loadSettings();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSettingsReturnsArrayFromRepository(): void
	{
		$settings = [
			'sentry' => 'test-sentry',
			'cache'  => ['enabled' => true],
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadSettings();

		$this->assertSame($settings, $result);
	}

	public function testLoadInstallationSettingsReturnsEmptyArrayWhenFileNotExists(): void
	{
		$this->installationRepository->method('load')->willReturn([]);

		$result = $this->fetcher->loadInstallationSettings();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadInstallationSettingsReturnsArrayFromRepository(): void
	{
		$settings = [
			'datadir' => 'test-data',
		];

		$this->installationRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadInstallationSettings();

		$this->assertSame($settings, $result);
	}

	public function testLoadSectionReturnsEmptyArrayWhenSettingsEmpty(): void
	{
		$this->settingsRepository->method('load')->willReturn([]);

		$result = $this->fetcher->loadSection('cache');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSectionReturnsSpecificSection(): void
	{
		$settings = [
			'sentry' => 'test',
			'cache'  => [
				'enabled' => true,
				'ttl'     => 3600,
			],
			'mailer' => [
				'host' => 'smtp.example.com',
			],
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadSection('cache');

		$this->assertSame($settings['cache'], $result);
	}

	public function testLoadSectionReturnsEmptyArrayForNonexistentSection(): void
	{
		$settings = [
			'cache' => ['enabled' => true],
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadSection('nonexistent');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testLoadSectionHandlesGeneralSettingsSpecially(): void
	{
		$settings = [
			'sentry'   => 'test-sentry',
			'notfound' => '404.html',
			'timezone' => 'UTC',
			'locale'   => 'en_US',                // moved to i18n section in 3.5
			'cache'    => ['enabled' => true],     // not part of general section
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadSection('general');

		$this->assertArrayHasKey('sentry', $result);
		$this->assertArrayHasKey('notfound', $result);
		$this->assertArrayHasKey('timezone', $result);
		// `locale` moved out of `general` and into the `i18n` section in 3.5,
		// so loadSection('general') no longer surfaces it even when it's
		// present at the top level of the settings file.
		$this->assertArrayNotHasKey('locale', $result);
		$this->assertArrayNotHasKey('cache', $result);
	}

	public function testLoadSectionGeneralReturnsOnlyPresentFields(): void
	{
		$settings = [
			'sentry'   => 'test-sentry',
			'timezone' => 'UTC',
			// Missing other general fields
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadSection('general');

		$this->assertCount(2, $result);
		$this->assertArrayHasKey('sentry', $result);
		$this->assertArrayHasKey('timezone', $result);
	}

	public function testLoadSectionInstallationReturnsInstallationSettings(): void
	{
		$installationSettings = [
			'datadir' => '/custom/data',
		];

		$this->installationRepository->method('load')->willReturn($installationSettings);

		$result = $this->fetcher->loadSection('installation');

		$this->assertSame($installationSettings, $result);
	}

	public function testLoadSettingsHandlesComplexNestedArrays(): void
	{
		$settings = [
			'cache' => [
				'redis' => [
					'host'    => 'localhost',
					'port'    => 6379,
					'options' => ['timeout' => 30],
				],
			],
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$result = $this->fetcher->loadSettings();

		$this->assertSame($settings, $result);
	}

	public function testLoadSectionMultipleSections(): void
	{
		$settings = [
			'cache'      => ['enabled' => true],
			'mailer'     => ['host' => 'smtp.example.com'],
			'imageworks' => ['quality' => 85],
		];

		$this->settingsRepository->method('load')->willReturn($settings);

		$cacheResult      = $this->fetcher->loadSection('cache');
		$mailerResult     = $this->fetcher->loadSection('mailer');
		$imageworksResult = $this->fetcher->loadSection('imageworks');

		$this->assertSame($settings['cache'], $cacheResult);
		$this->assertSame($settings['mailer'], $mailerResult);
		$this->assertSame($settings['imageworks'], $imageworksResult);
	}
}
