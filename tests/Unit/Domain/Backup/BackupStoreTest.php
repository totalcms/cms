<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Monolog\Level;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// BackupStore keeps per-item snapshot history. Sync feeds it the on-disk
// file before an upsert overwrites it (backupSchema/backupCollectionMeta/
// backupObject); the object lifecycle feeds it the pre-save state off the
// event payload (snapshotObject). These tests drive it against a real
// Flysystem adapter over a temp dir — the store is pure file plumbing, so
// mocking the filesystem would test the mock.

/** @param array<string,mixed> $backups */
function backupStoreConfig(array $backups): Config
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->backups = $backups;

	return $config;
}

describe('BackupStore', function (): void {
	beforeEach(function (): void {
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-backup-store-' . uniqid();
		mkdir($this->tmpRoot . '/.schemas', 0755, true);
		mkdir($this->tmpRoot . '/builder-pages', 0755, true);

		$storage = new StorageFilesystemAdapter(
			new Filesystem(new LocalFilesystemAdapter($this->tmpRoot))
		);

		// Stand-in for the real repository's format resolution: whichever of
		// {id}.json / {id}.md actually exists on disk, .json preferred.
		$tmpRoot = $this->tmpRoot;
		$objects = $this->createMock(ObjectRepository::class);
		$objects->method('objectPath')->willReturnCallback(function (string $collection, string $id) use ($tmpRoot): ?string {
			foreach (['.json', '.md'] as $ext) {
				$path = sprintf('%s/%s%s', $collection, $id, $ext);
				if (file_exists($tmpRoot . '/' . $path)) {
					return $path;
				}
			}

			return null;
		});

		$loggerFactory = new LoggerFactory([
			'level' => Level::Debug,
			'test'  => new NullLogger(),
		]);

		$this->make = fn (array $backups = []): BackupStore => new BackupStore(
			$storage,
			$objects,
			$loggerFactory,
			backupStoreConfig($backups),
		);

		$this->service = ($this->make)();
	});

	afterEach(function (): void {
		recursiveDelete($this->tmpRoot, forceComplete: true);
	});

	// ── Sync's file-based entry points (unchanged behaviour) ────────────────

	test('snapshots an existing schema into a datestamped per-id folder', function (): void {
		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"id":"products","v":1}');

		$this->service->backupSchema('products');

		$backups = glob($this->tmpRoot . '/.system/backups/schemas/products/products-*.json');
		expect($backups)->toHaveCount(1);
		expect(file_get_contents($backups[0]))->toBe('{"id":"products","v":1}');
		expect(basename($backups[0]))->toMatch('/^products-\d{8}-\d{6}\.json$/');
	});

	test('snapshots collection settings under collections/{id}', function (): void {
		file_put_contents($this->tmpRoot . '/builder-pages/.meta.json', '{"id":"builder-pages","mcp":{"access":"public"}}');

		$this->service->backupCollectionMeta('builder-pages');

		$backups = glob($this->tmpRoot . '/.system/backups/collections/builder-pages/builder-pages-*.json');
		expect($backups)->toHaveCount(1);
		expect(file_get_contents($backups[0]))->toBe('{"id":"builder-pages","mcp":{"access":"public"}}');
	});

	test('snapshots an existing object under objects/{collection}/{id}', function (): void {
		file_put_contents($this->tmpRoot . '/builder-pages/home.json', '{"id":"home","title":"Home"}');

		$this->service->backupObject('builder-pages', 'home');

		$backups = glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/home-*.json');
		expect($backups)->toHaveCount(1);
		expect(file_get_contents($backups[0]))->toBe('{"id":"home","title":"Home"}');
	});

	test('snapshots a markdown object under its .md name', function (): void {
		file_put_contents($this->tmpRoot . '/builder-pages/home.md', "---\nid: home\ntitle: Home\n---\n\nBody\n");

		$this->service->backupObject('builder-pages', 'home');

		$backups = glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/home-*.md');
		expect($backups)->toHaveCount(1);
		expect(file_get_contents($backups[0]))->toBe("---\nid: home\ntitle: Home\n---\n\nBody\n");
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
		// timestamp rather than sleeping a real second. Yesterday, not 2020 —
		// the age rule would prune a years-old file and hide what this tests.
		$existing = glob($dir . '/*.json')[0];
		rename($existing, sprintf('%s/products-%s-000000.json', $dir, date('Ymd', time() - 86400)));

		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"v":2}');
		$this->service->backupSchema('products');

		expect(glob($dir . '/*.json'))->toHaveCount(2);
	});

	test('prunes to the ten newest snapshots by default', function (): void {
		$dir = $this->tmpRoot . '/.system/backups/schemas/products';
		mkdir($dir, 0755, true);

		// Twelve fake historical snapshots, all dated today so the age rule
		// stays out of it and only the count rule is under test.
		$today = date('Ymd');
		foreach (range(1, 12) as $i) {
			file_put_contents(sprintf('%s/products-%s-%06d.json', $dir, $today, $i), '{"v":' . $i . '}');
		}

		file_put_contents($this->tmpRoot . '/.schemas/products.json', '{"v":"new"}');
		$this->service->backupSchema('products');

		$backups = glob($dir . '/*.json');
		expect($backups)->toHaveCount(10);

		// The oldest snapshots are the ones that went; the fresh write survives.
		$names = array_map(basename(...), $backups);
		sort($names);
		expect($names[0])->not->toBe(sprintf('products-%s-000001.json', $today));
		expect(file_get_contents($dir . '/' . end($names)))->toBe('{"v":"new"}');
	});

	test('refuses ids that could escape the backup tree', function (): void {
		$this->service->backupObject('../..', 'oops');
		$this->service->backupObject('builder-pages', '../../../etc/passwd');
		$this->service->backupSchema('');

		expect(is_dir($this->tmpRoot . '/.system/backups'))->toBeFalse();
	});

	// ── The lifecycle entry point: snapshot from decoded data ───────────────

	test('snapshotObject writes the given data as pretty JSON under objects/{collection}/{id}', function (): void {
		$this->service->snapshotObject('builder-pages', 'home', ['id' => 'home', 'title' => 'Home']);

		$backups = glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/home-*.json');
		expect($backups)->toHaveCount(1);
		expect(json_decode((string)file_get_contents($backups[0]), true))->toBe(['id' => 'home', 'title' => 'Home']);
	});

	test('snapshotObject always writes JSON even when the live file is markdown', function (): void {
		// A markdown collection's on-disk file is .md, but the lifecycle
		// snapshot is decoded data, so it lands as .json regardless. Restore
		// re-encodes, so a later format change does not strand history.
		file_put_contents($this->tmpRoot . '/builder-pages/home.md', "---\nid: home\n---\n\nBody\n");

		$this->service->snapshotObject('builder-pages', 'home', ['id' => 'home', 'content' => 'Body']);

		expect(glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/home-*.json'))->toHaveCount(1);
		expect(glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/home-*.md'))->toHaveCount(0);
	});

	test('snapshotObject does not stack a duplicate of the newest snapshot', function (): void {
		$this->service->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 1]);
		$this->service->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 1]);

		expect(glob($this->tmpRoot . '/.system/backups/objects/builder-pages/home/*.json'))->toHaveCount(1);
	});

	test('two different snapshots in the same second get distinct names that still sort newest-first', function (): void {
		$dir = $this->tmpRoot . '/.system/backups/objects/builder-pages/home';

		$this->service->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 1]);
		$this->service->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 2]);

		$names = array_map(basename(...), glob($dir . '/*.json'));
		expect($names)->toHaveCount(2);

		// One bare, one with the collision suffix; the suffixed one is newer.
		$plain    = array_values(array_filter($names, fn (string $n): bool => preg_match('/-\d{6}\.json$/', $n) === 1));
		$suffixed = array_values(array_filter($names, fn (string $n): bool => preg_match('/-\d{6}-1\.json$/', $n) === 1));
		expect($plain)->toHaveCount(1);
		expect($suffixed)->toHaveCount(1);

		$latest = $this->service->latestObjectSnapshot('builder-pages', 'home');
		expect($latest)->toBe($suffixed[0]);
		expect(json_decode((string)file_get_contents($dir . '/' . $latest), true)['v'])->toBe(2);
	});

	test('snapshotObject refuses ids that could escape the backup tree', function (): void {
		$this->service->snapshotObject('../..', 'oops', ['id' => 'oops']);
		$this->service->snapshotObject('builder-pages', '../../../etc/passwd', ['id' => 'x']);

		expect(is_dir($this->tmpRoot . '/.system/backups'))->toBeFalse();
	});

	// ── Retention: count AND age ───────────────────────────────────────────

	test('honours a configured keep count', function (): void {
		$store = ($this->make)(['keep' => 3]);
		$dir   = $this->tmpRoot . '/.system/backups/objects/builder-pages/home';
		mkdir($dir, 0755, true);

		$today = date('Ymd');
		foreach (range(1, 5) as $i) {
			file_put_contents(sprintf('%s/home-%s-%06d.json', $dir, $today, $i), '{"v":' . $i . '}');
		}

		$store->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 'new']);

		expect(glob($dir . '/*.json'))->toHaveCount(3);
	});

	test('prunes snapshots older than maxAgeDays even when under the count limit', function (): void {
		$store = ($this->make)(['keep' => 10, 'maxAgeDays' => 30]);
		$dir   = $this->tmpRoot . '/.system/backups/objects/builder-pages/home';
		mkdir($dir, 0755, true);

		// Two ancient, one recent.
		file_put_contents($dir . '/home-20200101-000000.json', '{"v":"ancient-1"}');
		file_put_contents($dir . '/home-20200102-000000.json', '{"v":"ancient-2"}');
		$recent = sprintf('home-%s-000000.json', date('Ymd', time() - 86400));
		file_put_contents($dir . '/' . $recent, '{"v":"recent"}');

		$store->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 'new']);

		$names = array_map(basename(...), glob($dir . '/*.json'));
		sort($names);
		expect($names)->toHaveCount(2);
		expect($names)->not->toContain('home-20200101-000000.json');
		expect($names)->not->toContain('home-20200102-000000.json');
		expect($names)->toContain($recent);
	});

	test('maxAgeDays of zero disables the age rule', function (): void {
		$store = ($this->make)(['keep' => 10, 'maxAgeDays' => 0]);
		$dir   = $this->tmpRoot . '/.system/backups/objects/builder-pages/home';
		mkdir($dir, 0755, true);

		file_put_contents($dir . '/home-20200101-000000.json', '{"v":"ancient"}');

		$store->snapshotObject('builder-pages', 'home', ['id' => 'home', 'v' => 'new']);

		expect(glob($dir . '/*.json'))->toHaveCount(2);
	});

	// ── Reading back ───────────────────────────────────────────────────────

	test('listObjectSnapshots returns newest first with parsed timestamp, format and size', function (): void {
		$dir = $this->tmpRoot . '/.system/backups/objects/builder-pages/home';
		mkdir($dir, 0755, true);

		file_put_contents($dir . '/home-20240105-120000.json', '{"v":1}');
		file_put_contents($dir . '/home-20240106-120000.md', "---\nid: home\n---\n");
		file_put_contents($dir . '/home-20240106-120000-1.json', '{"v":3}');
		file_put_contents($dir . '/not-a-snapshot.txt', 'noise');

		$list = $this->service->listObjectSnapshots('builder-pages', 'home');

		expect(array_column($list, 'file'))->toBe([
			'home-20240106-120000-1.json',
			'home-20240106-120000.md',
			'home-20240105-120000.json',
		]);
		expect($list[0]['format'])->toBe('json');
		expect($list[1]['format'])->toBe('markdown');
		expect($list[2]['at'])->toStartWith('2024-01-05T12:00:00');
		expect($list[0]['bytes'])->toBe(strlen('{"v":3}'));
	});

	test('listObjectSnapshots is empty for an object with no history', function (): void {
		expect($this->service->listObjectSnapshots('builder-pages', 'nothing'))->toBe([]);
		expect($this->service->latestObjectSnapshot('builder-pages', 'nothing'))->toBeNull();
	});

	test('readObjectSnapshot returns contents by bare filename and refuses paths', function (): void {
		$dir = $this->tmpRoot . '/.system/backups/objects/builder-pages/home';
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/home-20240105-120000.json', '{"v":1}');
		file_put_contents($this->tmpRoot . '/.schemas/secret.json', '{"secret":true}');

		expect($this->service->readObjectSnapshot('builder-pages', 'home', 'home-20240105-120000.json'))->toBe('{"v":1}');
		expect($this->service->readObjectSnapshot('builder-pages', 'home', 'missing.json'))->toBeNull();
		expect($this->service->readObjectSnapshot('builder-pages', 'home', '../../../../.schemas/secret.json'))->toBeNull();
		expect($this->service->readObjectSnapshot('builder-pages', 'home', '..'))->toBeNull();
	});
});
