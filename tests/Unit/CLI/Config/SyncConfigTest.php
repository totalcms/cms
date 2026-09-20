<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Config;

use PHPUnit\Framework\TestCase;
use TotalCMS\CLI\Config\SyncConfig;

/**
 * SyncConfig takes the resolved `sync` config bucket.
 *
 * It used to read tcms-data/.system/settings.json off disk itself, which
 * skipped the merge in config/settings.php entirely — so `sync` could only
 * ever come from settings.json and an operator could not set it in
 * config/tcms.php. That matters for installs sharing one data folder: they
 * share settings.json, so every site pushed to the same remote with the same
 * deploy key and had no way to override it per site.
 */
final class SyncConfigTest extends TestCase
{
	public function testNotConfiguredWhenBucketEmpty(): void
	{
		$config = new SyncConfig([]);

		expect($config->isConfigured())->toBeFalse();
		expect($config->getRemote())->toBeNull();
	}

	public function testNotConfiguredWhenUrlAndKeyBlank(): void
	{
		$config = new SyncConfig(['url' => '', 'key' => '']);

		expect($config->isConfigured())->toBeFalse();
		expect($config->getRemote())->toBeNull();
	}

	public function testNotConfiguredWhenMissingKey(): void
	{
		$config = new SyncConfig(['url' => 'https://example.com', 'key' => '']);

		expect($config->isConfigured())->toBeFalse();
	}

	public function testNotConfiguredWhenMissingUrl(): void
	{
		$config = new SyncConfig(['url' => '', 'key' => 'some-key']);

		expect($config->isConfigured())->toBeFalse();
	}

	public function testConfiguredWithValidUrlAndKey(): void
	{
		$config = new SyncConfig([
			'url' => 'https://production.example.com',
			'key' => 'api-key-123',
		]);

		expect($config->isConfigured())->toBeTrue();

		$remote = $config->getRemote();
		expect($remote)->not()->toBeNull();
		expect($remote['url'])->toBe('https://production.example.com');
		expect($remote['key'])->toBe('api-key-123');
	}

	public function testTrimsTrailingSlashFromUrl(): void
	{
		$config = new SyncConfig(['url' => 'https://example.com/tcms/', 'key' => 'key']);
		$remote = $config->getRemote();

		expect($remote['url'])->toBe('https://example.com/tcms');
	}

	public function testCoercesNonStringValues(): void
	{
		// settings.json is operator-editable and the JSON field accepts
		// anything, so neither value is guaranteed to arrive as a string.
		$config = new SyncConfig(['url' => 123, 'key' => null]);

		expect($config->isConfigured())->toBeFalse();
	}
}
