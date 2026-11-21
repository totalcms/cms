<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\BackupListCommand;
use TotalCMS\CLI\Command\BackupRestoreCommand;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Backup\Service\FileBackupStore;
use TotalCMS\Domain\Backup\Service\ObjectRestorer;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

/*
 * backup:list and backup:restore against a real container, for what they say
 * about an object's files: which earlier files are kept, which came back with
 * a restored record, and which the record names but nothing kept.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c       = $this->app->getContainer();
	$this->datadir = $this->c->get(Config::class)->datadir;

	$this->c->get(SchemaSaver::class)->saveSchema(['id' => 'papers', 'type' => 'object', 'properties' => [
		'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
		'title' => ['type' => 'string', 'field' => 'text'],
		'cover' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
	], 'index' => ['id', 'title']]);
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'papers', 'name' => 'Papers', 'schema' => 'papers']);
	$this->c->get(ObjectSaver::class)->saveObject('papers', ['id' => 'one', 'title' => 'One']);

	/** Upload hero.jpg, then replace it with second.jpg. */
	$this->replaceCover = function (): void {
		foreach (['hero.jpg' => 'test-image.jpg', 'second.jpg' => 'image-exif.jpg'] as $name => $fixture) {
			$dir = tcmsTestTempDir('backup-commands');
			is_dir($dir) || mkdir($dir, 0755, true);
			copy(testData($fixture), "{$dir}/{$name}");
			$this->c->get(SaverFactory::class)->generateSaverService('papers', 'cover', 'one')->save('papers', 'one', 'cover', "{$dir}/{$name}");
		}
	};

	/** The newest snapshot of the record that names this file. */
	$this->snapshotNaming = function (string $file): string {
		$store = $this->c->get(BackupStore::class);
		foreach ($store->listObjectSnapshots('papers', 'one') as $snapshot) {
			if (str_contains((string)$store->readObjectSnapshot('papers', 'one', $snapshot['file']), $file)) {
				return $snapshot['file'];
			}
		}

		throw new RuntimeException("No snapshot names {$file}");
	};

	$totalcms = $this->createMock(TotalCMS::class);
	$totalcms->method('collectionFetcher')->willReturn($this->c->get(CollectionFetcher::class));
	$totalcms->method('objectFetcher')->willReturn($this->c->get(ObjectFetcher::class));
	$totalcms->method('backupStore')->willReturn($this->c->get(BackupStore::class));
	$totalcms->method('fileBackupStore')->willReturn($this->c->get(FileBackupStore::class));
	$totalcms->method('objectRestorer')->willReturn($this->c->get(ObjectRestorer::class));

	$app = new Application();
	$app->addCommand(new BackupListCommand($totalcms));
	$app->addCommand(new BackupRestoreCommand($totalcms));
	$this->list    = new CommandTester($app->find('backup:list'));
	$this->restore = new CommandTester($app->find('backup:restore'));
});

describe('backup:list', function (): void {
	test('lists the files kept for the object, under its snapshots', function (): void {
		$this->c->get(Config::class)->backups = ['keepImages' => 2] + $this->c->get(Config::class)->backups;
		($this->replaceCover)();

		$this->list->execute(['collection' => 'papers', 'id' => 'one']);
		$display = $this->list->getDisplay();

		expect($display)->toContain('1 kept file')
			->and($display)->toMatch('/cover\s*\|\s*hero\.jpg\s*\|\s*\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/');

		$this->list->execute(['collection' => 'papers', 'id' => 'one', '--json' => true]);
		$json = json_decode($this->list->getDisplay(), true);

		expect($json['files'])->toHaveCount(1)
			// `path` is the folder under the field (a deck item's slot, a depot
			// folder), never where the kept file sits on disk.
			->and($json['files'][0])->toMatchArray(['property' => 'cover', 'path' => '', 'name' => 'hero.jpg', 'trash' => false])
			->and($json['files'][0])->toHaveKeys(['size', 'at']);
	});

	test('says nothing about files when none are kept', function (): void {
		($this->replaceCover)();

		$this->list->execute(['collection' => 'papers', 'id' => 'one']);

		expect($this->list->getDisplay())->not->toContain('kept file');

		$this->list->execute(['collection' => 'papers', 'id' => 'one', '--json' => true]);

		expect(json_decode($this->list->getDisplay(), true)['files'])->toBe([]);
	});
});

describe('backup:restore', function (): void {
	test('says which files came back with the record', function (): void {
		$this->c->get(Config::class)->backups = ['keepImages' => 2] + $this->c->get(Config::class)->backups;
		($this->replaceCover)();
		$snapshot = ($this->snapshotNaming)('hero.jpg');

		$this->restore->execute(['collection' => 'papers', 'id' => 'one', 'snapshot' => $snapshot, '--force' => true]);

		expect($this->restore->getStatusCode())->toBe(0)
			->and($this->restore->getDisplay())->toContain('cover: hero.jpg restored')
			->and(is_file("{$this->datadir}/papers/one/cover/hero.jpg"))->toBeTrue();
	});

	test('warns about a file the record names that was never kept, and still succeeds', function (): void {
		($this->replaceCover)();
		$snapshot = ($this->snapshotNaming)('hero.jpg');

		$this->restore->execute(['collection' => 'papers', 'id' => 'one', 'snapshot' => $snapshot, '--force' => true]);

		expect($this->restore->getStatusCode())->toBe(0)
			->and($this->restore->getDisplay())->toContain('cover: hero.jpg is missing')
			->and($this->restore->getDisplay())->toContain('keepImages');
	});

	test('the JSON result carries the files', function (): void {
		$this->c->get(Config::class)->backups = ['keepImages' => 2] + $this->c->get(Config::class)->backups;
		($this->replaceCover)();
		$snapshot = ($this->snapshotNaming)('hero.jpg');

		$this->restore->execute(['collection' => 'papers', 'id' => 'one', 'snapshot' => $snapshot, '--force' => true, '--json' => true]);

		expect(json_decode($this->restore->getDisplay(), true))->toMatchArray([
			'status' => 'restored',
			'files'  => [['property' => 'cover', 'path' => '', 'file' => 'hero.jpg', 'outcome' => 'restored']],
		]);
	});
});
