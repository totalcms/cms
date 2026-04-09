<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Config;

use PHPUnit\Framework\TestCase;
use TotalCMS\CLI\Config\SyncConfig;

final class SyncConfigTest extends TestCase
{
	private string $tmpDir;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-sync-test-' . uniqid();
		mkdir($this->tmpDir . '/.system', 0755, true);
	}

	protected function tearDown(): void
	{
		$settingsFile = $this->tmpDir . '/.system/settings.json';
		if (file_exists($settingsFile)) {
			unlink($settingsFile);
		}
		@rmdir($this->tmpDir . '/.system');
		@rmdir($this->tmpDir);
	}

	public function testNotConfiguredWhenNoSettingsFile(): void
	{
		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeFalse();
		expect($config->getRemote())->toBeNull();
	}

	public function testNotConfiguredWhenNoSyncSection(): void
	{
		$this->writeSettings(['smtp' => ['host' => 'localhost']]);

		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeFalse();
		expect($config->getRemote())->toBeNull();
	}

	public function testNotConfiguredWhenSyncEmpty(): void
	{
		$this->writeSettings(['sync' => ['url' => '', 'key' => '']]);

		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeFalse();
		expect($config->getRemote())->toBeNull();
	}

	public function testNotConfiguredWhenMissingKey(): void
	{
		$this->writeSettings(['sync' => ['url' => 'https://example.com', 'key' => '']]);

		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeFalse();
	}

	public function testNotConfiguredWhenMissingUrl(): void
	{
		$this->writeSettings(['sync' => ['url' => '', 'key' => 'some-key']]);

		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeFalse();
	}

	public function testConfiguredWithValidUrlAndKey(): void
	{
		$this->writeSettings(['sync' => [
			'url' => 'https://production.example.com',
			'key' => 'api-key-123',
		]]);

		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeTrue();

		$remote = $config->getRemote();
		expect($remote)->not()->toBeNull();
		expect($remote['url'])->toBe('https://production.example.com');
		expect($remote['key'])->toBe('api-key-123');
	}

	public function testTrimsTrailingSlashFromUrl(): void
	{
		$this->writeSettings(['sync' => [
			'url' => 'https://example.com/tcms/',
			'key' => 'key',
		]]);

		$config = new SyncConfig($this->tmpDir);
		$remote = $config->getRemote();

		expect($remote['url'])->toBe('https://example.com/tcms');
	}

	public function testHandlesInvalidJsonGracefully(): void
	{
		file_put_contents($this->tmpDir . '/.system/settings.json', 'not json');

		$config = new SyncConfig($this->tmpDir);

		expect($config->isConfigured())->toBeFalse();
		expect($config->getRemote())->toBeNull();
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function writeSettings(array $settings): void
	{
		file_put_contents(
			$this->tmpDir . '/.system/settings.json',
			(string)json_encode($settings, JSON_PRETTY_PRINT)
		);
	}
}
