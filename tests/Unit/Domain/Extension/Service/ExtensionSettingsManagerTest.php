<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

describe('ExtensionSettingsManager', function (): void {
	test('returns empty array when no settings exist', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);

		$manager  = new ExtensionSettingsManager($storage);
		$settings = $manager->getSettings('vendor/ext');

		expect($settings)->toBe([]);
	});

	test('loads settings from storage', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'greeting' => 'Hello',
			'enabled'  => true,
		]));

		$manager  = new ExtensionSettingsManager($storage);
		$settings = $manager->getSettings('vendor/ext');

		expect($settings)->toBe(['greeting' => 'Hello', 'enabled' => true]);
	});

	test('caches settings across calls', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode(['key' => 'value']));
		$storage->expects(test()->once())->method('read');

		$manager = new ExtensionSettingsManager($storage);
		$manager->getSettings('vendor/ext');
		$manager->getSettings('vendor/ext');
	});

	test('getSetting returns specific key with default', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode(['greeting' => 'Hi']));

		$manager = new ExtensionSettingsManager($storage);

		expect($manager->getSetting('vendor/ext', 'greeting'))->toBe('Hi');
		expect($manager->getSetting('vendor/ext', 'missing', 'fallback'))->toBe('fallback');
		expect($manager->getSetting('vendor/ext', 'missing'))->toBeNull();
	});

	test('saves settings to storage', function (): void {
		$writtenPath    = '';
		$writtenContent = '';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturnCallback(function (string $path, string $content) use (&$writtenPath, &$writtenContent): bool {
			$writtenPath    = $path;
			$writtenContent = $content;

			return true;
		});

		$manager = new ExtensionSettingsManager($storage);
		$manager->saveSettings('vendor/ext', ['greeting' => 'Hello']);

		expect($writtenPath)->toContain('vendor/ext.json');
		$decoded = json_decode($writtenContent, true);
		expect($decoded)->toBe(['greeting' => 'Hello']);
	});

	test('saved settings are reflected in subsequent reads', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturn(true);

		$manager = new ExtensionSettingsManager($storage);
		$manager->saveSettings('vendor/ext', ['key' => 'new-value']);

		expect($manager->getSettings('vendor/ext'))->toBe(['key' => 'new-value']);
		expect($manager->getSetting('vendor/ext', 'key'))->toBe('new-value');
	});

	test('deletes settings from storage', function (): void {
		$deletedPath = '';
		$fileExists  = true;

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturnCallback(function () use (&$fileExists): bool {
			return $fileExists;
		});
		$storage->method('read')->willReturn(json_encode(['key' => 'value']));
		$storage->method('delete')->willReturnCallback(function (string $path) use (&$deletedPath, &$fileExists): bool {
			$deletedPath = $path;
			$fileExists  = false;

			return true;
		});

		$manager = new ExtensionSettingsManager($storage);

		// Verify data exists before delete
		expect($manager->getSettings('vendor/ext'))->toBe(['key' => 'value']);

		$manager->deleteSettings('vendor/ext');

		expect($deletedPath)->toContain('vendor/ext.json');
		// After delete, cache is cleared and file no longer exists
		expect($manager->getSettings('vendor/ext'))->toBe([]);
	});

	test('handles invalid JSON gracefully', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn('not valid json');

		$manager = new ExtensionSettingsManager($storage);

		expect($manager->getSettings('vendor/ext'))->toBe([]);
	});

	test('isolates settings between extensions', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturn(true);

		$manager = new ExtensionSettingsManager($storage);
		$manager->saveSettings('vendor/ext-a', ['key' => 'a']);
		$manager->saveSettings('vendor/ext-b', ['key' => 'b']);

		expect($manager->getSettings('vendor/ext-a'))->toBe(['key' => 'a']);
		expect($manager->getSettings('vendor/ext-b'))->toBe(['key' => 'b']);
	});
});
