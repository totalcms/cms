<?php

declare(strict_types=1);

use TotalCMS\Domain\Migration\Repository\MigrationStateRepository;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

describe('MigrationStateRepository', function (): void {
	test('hasRun returns false when ledger does not exist', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);

		$repo = new MigrationStateRepository($storage);

		expect($repo->hasRun('templates-to-builder'))->toBeFalse();
	});

	test('hasRun returns true for migrations recorded in the ledger', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'templates-to-builder' => ['ranAt' => '2026-05-18T12:00:00Z', 'result' => 14],
		]));

		$repo = new MigrationStateRepository($storage);

		expect($repo->hasRun('templates-to-builder'))->toBeTrue();
		expect($repo->hasRun('something-else'))->toBeFalse();
	});

	test('corrupt ledger does not throw — treated as empty', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn('{not valid json');

		$repo = new MigrationStateRepository($storage);

		expect($repo->hasRun('anything'))->toBeFalse();
	});

	test('malformed entries are skipped', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn(json_encode([
			'good'    => ['ranAt' => '2026-05-18T12:00:00Z', 'result' => 1],
			'bad'     => 'not an array',
			'partial' => ['ranAt' => '2026-05-18T12:00:00Z'],
		]));

		$repo = new MigrationStateRepository($storage);

		expect($repo->hasRun('good'))->toBeTrue();
		expect($repo->hasRun('bad'))->toBeFalse();
		expect($repo->hasRun('partial'))->toBeFalse();
	});

	test('recordRan writes the entry through the adapter, which replaces the file atomically', function (): void {
		$writtenPath = '';
		$writtenJson = '';

		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturnCallback(function (string $path, string $contents) use (&$writtenPath, &$writtenJson): bool {
			$writtenPath = $path;
			$writtenJson = $contents;

			return true;
		});
		$storage->expects(test()->never())->method('move');

		$repo = new MigrationStateRepository($storage);
		$repo->recordRan('templates-to-builder', 14);

		// The temp file and rename are StorageFilesystemAdapter::write()'s job
		// (StorageFilesystemAdapterTest pins them); the store writes the real path.
		expect($writtenPath)->toBe('.system/migrations.json');

		$decoded = json_decode($writtenJson, true);
		expect($decoded)->toHaveKey('templates-to-builder');
		expect($decoded['templates-to-builder']['result'])->toBe(14);
		expect($decoded['templates-to-builder']['ranAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
	});

	test('recordRan updates the cache so hasRun reflects it immediately', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$storage->method('write')->willReturn(true);
		$storage->method('move')->willReturn(true);

		$repo = new MigrationStateRepository($storage);

		expect($repo->hasRun('templates-to-builder'))->toBeFalse();

		$repo->recordRan('templates-to-builder', 14);

		expect($repo->hasRun('templates-to-builder'))->toBeTrue();
	});

	test('a malformed ledger reads as nothing-ran and is not overwritten', function (): void {
		$storage = test()->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(true);
		$storage->method('read')->willReturn('{"templates-to-builder": {"ranAt"');
		$storage->expects(test()->never())->method('move');

		$repo = new MigrationStateRepository($storage);

		expect($repo->hasRun('templates-to-builder'))->toBeFalse();
		$repo->recordRan('templates-to-builder', 1);   // logged + refused, not fatal
		expect($repo->hasRun('templates-to-builder'))->toBeTrue();
	});
});
