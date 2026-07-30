<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Monolog\Level;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Sync\Service\SyncBackupService;
use TotalCMS\Factory\LoggerFactory;

// SyncBackupService snapshots schemas/objects before a sync upsert overwrites
// them, on the machine being overwritten. These tests drive it against a real
// Flysystem adapter over a temp dir — the service is pure file plumbing, so
// mocking the filesystem would test the mock.

describe('SyncBackupService', function (): void {
	beforeEach(function (): void {
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-sync-backup-' . uniqid();
		mkdir($this->tmpRoot . '/.schemas', 0755, true);
		mkdir($this->tmpRoot . '/builder-pages', 0755, true);

		$storage = new StorageFilesystemAdapter(
			new Filesystem(new LocalFilesystemAdapter($this->tmpRoot))
		);

		$this->service = new SyncBackupService($storage, new LoggerFactory([
			'level' => Level::Debug,
			'test'  => new NullLogger(),
		]));
	});

	afterEach(function (): void {
		recursiveDelete($this->tmpRoot, forceComplete: true);
	});

	test('snapshots an existing schema into a datestamped per-id folder', function (): void {
		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"id":"products","v":1}');

		$this->service->backupSchema('products');

		$backups = glob($this->tmpRoot . '/.system/backups/schemas/products/products-*.json');
		expect($backups)->toHaveCount(1);
		expect(file_get_contents($backups[0]))->toBe('{"id":"products","v":1}');
		expect(basename($backups[0]))->toMatch('/^products-\d{8}-\d{6}\.json$/');
	});

	test('snapshots an existing object under objects/{collection}/{id}', function (): void {
		file_put_contents($this->tmpRoot . '/builder-pages/home.json', '{"id":"home","title":"Home"}');

		$this->service->backupObject('builder-pages', 'home');

		$backups = glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/home-*.json');
		expect($backups)->toHaveCount(1);
		expect(file_get_contents($backups[0]))->toBe('{"id":"home","title":"Home"}');
	});

	test('does nothing when the source does not exist (a create, nothing to lose)', function (): void {
		$this->service->backupSchema('brand-new');
		$this->service->backupObject('builder-pages', 'brand-new');

		expect(is_dir($this->tmpRoot . '/.system/backups'))->toBeFalse();
	});

	test('does not stack a duplicate when content is unchanged since the newest backup', function (): void {
		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"v":1}');

		$this->service->backupSchema('products');
		$this->service->backupSchema('products'); // same content again — a re-run of the same push

		expect(glob($this->tmpRoot . '/.system/backups/schemas/products/*.json'))->toHaveCount(1);
	});

	test('writes a new snapshot when content changed', function (): void {
		$dir = $this->tmpRoot . '/.system/backups/schemas/products';

		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"v":1}');
		$this->service->backupSchema('products');

		// Newest-first detection is filename-based, so simulate an earlier
		// timestamp rather than sleeping a real second.
		$existing = glob($dir . '/*.json')[0];
		rename($existing, $dir . '/products-20200101-000000.json');

		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"v":2}');
		$this->service->backupSchema('products');

		$backups = glob($dir . '/*.json');
		expect($backups)->toHaveCount(2);
	});

	test('prunes to the ten newest snapshots', function (): void {
		$dir = $this->tmpRoot . '/.system/backups/schemas/products';
		mkdir($dir, 0755, true);

		// Seed 12 fake historical snapshots with distinct timestamps.
		foreach (range(1, 12) as $i) {
			file_put_contents(sprintf('%s/products-202001%02d-000000.json', $dir, $i), '{"v":' . $i . '}');
		}

		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"v":"new"}');
		$this->service->backupSchema('products');

		$backups = glob($dir . '/*.json');
		expect($backups)->toHaveCount(10);

		// The oldest snapshots are the ones that went; the fresh write survives.
		$names = array_map(basename(...), $backups);
		sort($names);
		expect($names[0])->not->toBe('products-20200101-000000.json');
		expect(file_get_contents($dir . '/' . end($names)))->toBe('{"v":"new"}');
	});

	test('refuses ids that could escape the backup tree', function (): void {
		$this->service->backupObject('../..', 'oops');
		$this->service->backupObject('builder-pages', '../../../etc/passwd');
		$this->service->backupSchema('');

		expect(is_dir($this->tmpRoot . '/.system/backups'))->toBeFalse();
	});
});
