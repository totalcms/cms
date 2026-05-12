<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

describe('ExtensionStateRepository', function (): void {
	test('returns empty when no state file exists', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);

		$repo = new ExtensionStateRepository($storage);

		expect($repo->loadAll())->toBe([]);
	});

	test('loads states from storage', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/ext-a' => [
				'enabled'      => true,
				'installed_at' => '2026-04-15T10:00:00Z',
				'version'      => '1.0.0',
				'error'        => null,
				'permissions'  => ['twig:functions' => true],
			],
			'vendor/ext-b' => [
				'enabled'      => false,
				'installed_at' => '2026-04-15T10:00:00Z',
				'version'      => '2.0.0',
				'error'        => 'boot() failed',
			],
		]));

		$repo   = new ExtensionStateRepository($storage);
		$states = $repo->loadAll();

		expect($states)->toHaveCount(2);
		expect($states['vendor/ext-a']->enabled)->toBeTrue();
		expect($states['vendor/ext-a']->permissions)->toBe(['twig:functions' => true]);
		expect($states['vendor/ext-b']->enabled)->toBeFalse();
		expect($states['vendor/ext-b']->error)->toBe('boot() failed');
	});

	test('getState returns null for unknown extension', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);

		$repo = new ExtensionStateRepository($storage);

		expect($repo->getState('vendor/unknown'))->toBeNull();
	});

	test('getState returns state for known extension', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));

		$repo  = new ExtensionStateRepository($storage);
		$state = $repo->getState('vendor/ext');

		expect($state)->toBeInstanceOf(ExtensionState::class);
		expect($state->enabled)->toBeTrue();
	});

	test('isEnabled returns correct state', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/enabled'  => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
			'vendor/disabled' => ['enabled' => false, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));

		$repo = new ExtensionStateRepository($storage);

		expect($repo->isEnabled('vendor/enabled'))->toBeTrue();
		expect($repo->isEnabled('vendor/disabled'))->toBeFalse();
		expect($repo->isEnabled('vendor/unknown'))->toBeFalse();
	});

	test('saveState persists to storage', function (): void {
		$writtenContent = '';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturnCallback(function (string $path, string $content) use (&$writtenContent): bool {
			$writtenContent = $content;

			return true;
		});

		$repo = new ExtensionStateRepository($storage);
		$repo->saveState('vendor/ext', new ExtensionState(
			enabled: true,
			installedAt: '2026-04-15T10:00:00Z',
			version: '1.0.0',
			permissions: ['twig:functions' => true],
		));

		$decoded = json_decode($writtenContent, true);
		expect($decoded)->toHaveKey('vendor/ext');
		expect($decoded['vendor/ext']['enabled'])->toBeTrue();
		expect($decoded['vendor/ext']['permissions'])->toBe(['twig:functions' => true]);
	});

	test('saveState updates cache for subsequent reads', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturn(true);

		$repo = new ExtensionStateRepository($storage);

		expect($repo->isEnabled('vendor/ext'))->toBeFalse();

		$repo->saveState('vendor/ext', new ExtensionState(enabled: true));

		expect($repo->isEnabled('vendor/ext'))->toBeTrue();
	});

	test('removeState deletes from cache and persists', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturn(true);

		$repo = new ExtensionStateRepository($storage);

		expect($repo->getState('vendor/ext'))->not->toBeNull();

		$repo->removeState('vendor/ext');

		expect($repo->getState('vendor/ext'))->toBeNull();
	});

	test('recordError updates error field', function (): void {
		$writtenContent = '';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->method('write')->willReturnCallback(function (string $path, string $content) use (&$writtenContent): bool {
			$writtenContent = $content;

			return true;
		});

		$repo = new ExtensionStateRepository($storage);
		$repo->recordError('vendor/ext', 'register() failed');

		$state = $repo->getState('vendor/ext');
		expect($state->error)->toBe('register() failed');
	});

	test('recordError ignores unknown extension', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->expects(test()->never())->method('write');

		$repo = new ExtensionStateRepository($storage);
		$repo->recordError('vendor/unknown', 'error');
	});

	test('clearError removes error field', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => 'old error'],
		]));
		$storage->method('write')->willReturn(true);

		$repo = new ExtensionStateRepository($storage);
		$repo->clearError('vendor/ext');

		expect($repo->getState('vendor/ext')->error)->toBeNull();
	});

	test('clearError is a no-op when no error exists', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'vendor/ext' => ['enabled' => true, 'installed_at' => '', 'version' => '1.0.0', 'error' => null],
		]));
		$storage->expects(test()->never())->method('write');

		$repo = new ExtensionStateRepository($storage);
		$repo->clearError('vendor/ext');
	});
});
