<?php

declare(strict_types=1);

use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Backup\Service\ObjectRestorer;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Service\RemoverFactory;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

/*
 * File history through the real container: an upload, a replace, a remove, a
 * delete and a restore, with nothing mocked. The unit tests prove each piece;
 * this proves they are wired to each other and that the settings reach them.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c       = $this->app->getContainer();
	$this->datadir = $this->c->get(Config::class)->datadir;

	/** Settings are read when a store is first built, so set them before anything else runs. */
	$this->backups = function (array $settings): void {
		$config          = $this->c->get(Config::class);
		$config->backups = $settings + $config->backups;
	};

	$this->seed = function (): void {
		$schemas = $this->c->get(SchemaSaver::class);
		$schemas->saveSchema(['id' => 'member', 'type' => 'object', 'properties' => [
			'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'name'  => ['type' => 'string', 'field' => 'text'],
			'photo' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image', 'settings' => ['extractPalette' => false]],
			'cv'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
		]]);
		$member = 'https://www.totalcms.co/schemas/custom/member.json';
		$schemas->saveSchema(['id' => 'papers', 'type' => 'object', 'properties' => [
			'id'        => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
			'title'     => ['type' => 'string', 'field' => 'text'],
			'cover'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image'],
			'pdf'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/file.json', 'field' => 'file'],
			'photos'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery'],
			'downloads' => ['$ref' => 'https://www.totalcms.co/schemas/properties/depot.json', 'field' => 'depot'],
			'lead'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/card.json', 'field' => 'card', 'schemaref' => $member],
			'team'      => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck', 'schemaref' => $member],
		], 'index' => ['id', 'title']]);
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'papers', 'name' => 'Papers', 'schema' => 'papers']);
		$this->c->get(ObjectSaver::class)->saveObject('papers', [
			'id'    => 'one',
			'title' => 'One',
			'lead'  => ['name' => 'Lead'],
			'team'  => ['alice' => ['id' => 'alice', 'name' => 'Alice'], 'bob' => ['id' => 'bob', 'name' => 'Bob']],
		]);
	};

	/**
	 * Upload a copy of a fixture under a chosen name: the saver takes the file
	 * it is given. `$subpath` is a slot in a card or deck item, or a depot folder.
	 */
	$this->upload = function (string $property, string $name, string $fixture = 'test-image.jpg', ?string $contents = null, ?string $subpath = null): void {
		$dir = tcmsTestTempDir('file-history');
		is_dir($dir) || mkdir($dir, 0755, true);
		$tmp = "{$dir}/{$name}";
		$contents === null ? copy(testData($fixture), $tmp) : file_put_contents($tmp, $contents);

		$this->c->get(SaverFactory::class)->generateSaverService('papers', $property, 'one', $subpath)->save('papers', 'one', $property, $tmp, $subpath);
	};

	/** @return list<string> the files in a property's folder, or in a folder under it */
	$this->live = function (string $dir): array {
		$names = array_map(basename(...), array_filter(glob("{$this->datadir}/papers/one/{$dir}/*") ?: [], is_file(...)));
		sort($names);

		return $names;
	};

	/** @return list<string> what is kept for a property, as `folder…/name` without the dated folder */
	$this->kept = function (string $property, string $area = 'files'): array {
		$base  = "{$this->datadir}/.system/backups/objects/papers/one/{$area}/{$property}";
		$found = [];
		if (is_dir($base)) {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
				$path    = substr((string)$file, strlen($base) + 1);
				$found[] = substr($path, (int)strpos($path, '/') + 1);
			}
		}
		sort($found);

		return $found;
	};

	$this->record = fn (): array => $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('papers', 'one')->toArray();

	$this->restore = fn (string $namingFile): array => $this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', ($this->snapshotNaming)($namingFile))['files'];

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
});

test('out of the box nothing changes: a replaced image is deleted, as it always was', function (): void {
	($this->seed)();
	($this->upload)('cover', 'hero.jpg');
	($this->upload)('cover', 'second.jpg', 'image-exif.jpg');

	expect(($this->live)('cover'))->toBe(['second.jpg'])
		->and(is_dir("{$this->datadir}/.system/backups/objects/papers/one/files"))->toBeFalse();
});

test('a replaced image is kept, and restoring the record brings it back', function (): void {
	($this->backups)(['keepImages' => 2]);
	($this->seed)();
	($this->upload)('cover', 'hero.jpg');
	$original = (string)file_get_contents("{$this->datadir}/papers/one/cover/hero.jpg");
	($this->upload)('cover', 'second.jpg', 'image-exif.jpg');

	expect(($this->live)('cover'))->toBe(['second.jpg'])
		->and(($this->kept)('cover'))->toBe(['hero.jpg']);

	$result = $this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', ($this->snapshotNaming)('hero.jpg'));

	expect($result['files'])->toBe([['property' => 'cover', 'path' => '', 'file' => 'hero.jpg', 'outcome' => 'restored']])
		->and(($this->live)('cover'))->toBe(['hero.jpg'])
		->and(file_get_contents("{$this->datadir}/papers/one/cover/hero.jpg"))->toBe($original)
		->and($this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('papers', 'one')->toArray()['cover']['name'])->toBe('hero.jpg')
		// The image the restore displaced is the one kept now: restoring again undoes it.
		->and(($this->kept)('cover'))->toBe(['second.jpg']);

	$this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', ($this->snapshotNaming)('second.jpg'));

	expect(($this->live)('cover'))->toBe(['second.jpg'])
		->and(($this->kept)('cover'))->toBe(['hero.jpg']);
});

test('an image replaced by another of the same name is restored, not mistaken for itself', function (): void {
	($this->backups)(['keepImages' => 2]);
	($this->seed)();
	($this->upload)('cover', 'hero.jpg');
	$original = (string)file_get_contents("{$this->datadir}/papers/one/cover/hero.jpg");
	($this->upload)('cover', 'hero.jpg', 'image-exif.jpg');

	expect(file_get_contents("{$this->datadir}/papers/one/cover/hero.jpg"))->not->toBe($original);

	$result = $this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', ($this->snapshotNaming)('hero.jpg'));

	expect($result['files'])->toBe([['property' => 'cover', 'path' => '', 'file' => 'hero.jpg', 'outcome' => 'restored']])
		->and(file_get_contents("{$this->datadir}/papers/one/cover/hero.jpg"))->toBe($original);
});

test('files have their own setting, and each property keeps only that many', function (): void {
	($this->backups)(['keepFiles' => 2]);
	($this->seed)();
	foreach (['v1.txt', 'v2.txt', 'v3.txt', 'v4.txt'] as $name) {
		($this->upload)('pdf', $name, contents: "contents of {$name}");
	}
	// Images were left at zero.
	($this->upload)('cover', 'hero.jpg');
	($this->upload)('cover', 'second.jpg', 'image-exif.jpg');

	$kept = ($this->kept)('pdf');
	sort($kept);

	expect(($this->live)('pdf'))->toBe(['v4.txt'])
		->and($kept)->toBe(['v2.txt', 'v3.txt'])
		->and(($this->kept)('cover'))->toBe([]);
});

test('a removed file is kept', function (): void {
	($this->backups)(['keepFiles' => 1]);
	($this->seed)();
	($this->upload)('pdf', 'brochure.txt', contents: 'pages');

	$this->c->get(RemoverFactory::class)->generateRemoverService('papers', 'pdf')->deleteFile('papers', 'one', 'pdf', 'brochure.txt');

	expect(($this->live)('pdf'))->toBe([])
		->and(($this->kept)('pdf'))->toBe(['brochure.txt']);
});

test('a property that is cleared keeps its file', function (): void {
	($this->backups)(['keepImages' => 1]);
	($this->seed)();
	($this->upload)('cover', 'hero.jpg');

	$this->c->get(ObjectRemover::class)->deleteObjectProperty('papers', 'one', 'cover');

	expect(($this->live)('cover'))->toBe([])
		->and(($this->kept)('cover'))->toBe(['hero.jpg']);
});

test('a deleted object comes back with its image and its file', function (): void {
	($this->backups)(['keepImages' => 1, 'keepFiles' => 1]);
	($this->seed)();
	($this->upload)('cover', 'hero.jpg');
	($this->upload)('pdf', 'brochure.txt', contents: 'pages');

	$this->c->get(ObjectRemover::class)->deleteObject('papers', 'one');

	expect(is_dir("{$this->datadir}/papers/one"))->toBeFalse()
		->and(($this->kept)('cover'))->toBe(['hero.jpg'])
		->and(($this->kept)('pdf'))->toBe(['brochure.txt']);

	$store  = $this->c->get(BackupStore::class);
	$result = $this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', (string)$store->latestObjectSnapshot('papers', 'one'));

	expect(array_column($result['files'], 'outcome', 'property'))->toBe(['cover' => 'restored', 'pdf' => 'restored'])
		->and(($this->live)('cover'))->toBe(['hero.jpg'])
		->and(file_get_contents("{$this->datadir}/papers/one/pdf/brochure.txt"))->toBe('pages');
});

test('with history off, a restore says which files it could not bring back', function (): void {
	($this->seed)();
	($this->upload)('cover', 'hero.jpg');
	($this->upload)('cover', 'second.jpg', 'image-exif.jpg');

	$result = $this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', ($this->snapshotNaming)('hero.jpg'));

	expect($result['files'])->toBe([['property' => 'cover', 'path' => '', 'file' => 'hero.jpg', 'outcome' => 'missing']])
		// The image that is there is left alone: nothing was destroyed to restore a record.
		->and(($this->live)('cover'))->toBe(['second.jpg']);
});

describe('inside a card or a deck', function (): void {
	test('a replaced image in a card comes back with the record', function (): void {
		($this->backups)(['keepImages' => 1]);
		($this->seed)();
		($this->upload)('lead', 'first.jpg', subpath: 'photo');
		($this->upload)('lead', 'second.jpg', 'image-exif.jpg', subpath: 'photo');

		expect(($this->live)('lead/photo'))->toBe(['second.jpg'])
			->and(($this->kept)('lead'))->toBe(['photo/first.jpg']);

		expect(($this->restore)('first.jpg'))->toBe([['property' => 'lead', 'path' => 'photo', 'file' => 'first.jpg', 'outcome' => 'restored']])
			->and(($this->live)('lead/photo'))->toBe(['first.jpg'])
			->and(($this->record)()['lead']['photo']['name'])->toBe('first.jpg')
			->and(($this->kept)('lead'))->toBe(['photo/second.jpg']);
	});

	test('a file in a deck item follows the file setting, and an image the image setting', function (): void {
		($this->backups)(['keepFiles' => 1]);
		($this->seed)();
		($this->upload)('team', 'cv1.txt', contents: 'one', subpath: 'alice/cv');
		($this->upload)('team', 'cv2.txt', contents: 'two', subpath: 'alice/cv');
		($this->upload)('team', 'a1.jpg', subpath: 'alice/photo');
		($this->upload)('team', 'a2.jpg', 'image-exif.jpg', subpath: 'alice/photo');

		expect(($this->kept)('team'))->toBe(['alice/cv/cv1.txt']);
	});

	test('a deck item removed from the record takes its files into history, and a restore brings the item back whole', function (): void {
		($this->backups)(['keepImages' => 1, 'keepFiles' => 1]);
		($this->seed)();
		($this->upload)('team', 'alice.jpg', subpath: 'alice/photo');
		($this->upload)('team', 'alice.txt', contents: 'cv', subpath: 'alice/cv');
		($this->upload)('team', 'bob.jpg', 'image-exif.jpg', subpath: 'bob/photo');

		$record = ($this->record)();
		unset($record['team']['alice']);
		$this->c->get(ObjectUpdater::class)->updateObject('papers', 'one', $record);

		expect(is_dir("{$this->datadir}/papers/one/team/alice"))->toBeFalse()
			->and(($this->kept)('team'))->toBe(['alice/cv/alice.txt', 'alice/photo/alice.jpg'])
			->and(($this->live)('team/bob/photo'))->toBe(['bob.jpg']);

		$files = ($this->restore)('alice.jpg');
		usort($files, fn (array $a, array $b): int => $a['path'] <=> $b['path']);

		expect($files)->toBe([
			['property' => 'team', 'path' => 'alice/cv', 'file' => 'alice.txt', 'outcome' => 'restored'],
			['property' => 'team', 'path' => 'alice/photo', 'file' => 'alice.jpg', 'outcome' => 'restored'],
		])
			->and(file_get_contents("{$this->datadir}/papers/one/team/alice/cv/alice.txt"))->toBe('cv')
			->and(($this->live)('team/alice/photo'))->toBe(['alice.jpg'])
			->and(($this->kept)('team'))->toBe([]);
	});

	test('clearing an image inside a deck item keeps it', function (): void {
		($this->backups)(['keepImages' => 1]);
		($this->seed)();
		($this->upload)('team', 'alice.jpg', subpath: 'alice/photo');

		$this->c->get(ObjectRemover::class)->deleteNestedProperty('papers', 'one', 'team', 'alice/photo');

		expect(($this->kept)('team'))->toBe(['alice/photo/alice.jpg']);
	});

	test('a deleted object comes back with the files inside its card and deck', function (): void {
		($this->backups)(['keepImages' => 1, 'keepFiles' => 1]);
		($this->seed)();
		($this->upload)('lead', 'lead.jpg', subpath: 'photo');
		($this->upload)('team', 'bob.txt', contents: 'cv', subpath: 'bob/cv');

		$this->c->get(ObjectRemover::class)->deleteObject('papers', 'one');
		$store = $this->c->get(BackupStore::class);
		$this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', (string)$store->latestObjectSnapshot('papers', 'one'));

		expect(($this->live)('lead/photo'))->toBe(['lead.jpg'])
			->and(file_get_contents("{$this->datadir}/papers/one/team/bob/cv/bob.txt"))->toBe('cv');
	});
});

describe('the trash', function (): void {
	test('is off by default: a removed gallery picture is deleted, whatever the image setting', function (): void {
		($this->backups)(['keepImages' => 5, 'keepFiles' => 5]);
		($this->seed)();
		($this->upload)('photos', 'a.jpg');

		$this->c->get(RemoverFactory::class)->generateRemoverService('papers', 'photos')->deleteFile('papers', 'one', 'photos', 'a.jpg');

		expect(($this->live)('photos'))->toBe([])
			->and(is_dir("{$this->datadir}/.system/backups/objects/papers/one/trash"))->toBeFalse();
	});

	test('a picture removed from a gallery goes to the trash and comes back with the record', function (): void {
		($this->backups)(['trash' => true]);
		($this->seed)();
		($this->upload)('photos', 'a.jpg');
		($this->upload)('photos', 'b.jpg', 'image-exif.jpg');

		$this->c->get(RemoverFactory::class)->generateRemoverService('papers', 'photos')->deleteFile('papers', 'one', 'photos', 'a.jpg');

		expect(($this->live)('photos'))->toBe(['b.jpg'])
			->and(($this->kept)('photos', 'trash'))->toBe(['a.jpg']);

		expect(($this->restore)('a.jpg'))->toBe([['property' => 'photos', 'path' => '', 'file' => 'a.jpg', 'outcome' => 'restored']])
			->and(($this->live)('photos'))->toBe(['a.jpg', 'b.jpg'])
			->and(array_column(($this->record)()['photos'], 'name'))->toBe(['a.jpg', 'b.jpg'])
			->and(($this->kept)('photos', 'trash'))->toBe([]);
	});

	test('a depot file and a depot folder go to the trash, and come back to their folders', function (): void {
		($this->backups)(['trash' => true]);
		($this->seed)();
		($this->upload)('downloads', 'readme.txt', contents: 'read me');
		($this->upload)('downloads', 'a.txt', contents: 'prices', subpath: 'Price Lists');
		($this->upload)('downloads', 'b.txt', contents: 'more', subpath: 'Price Lists');
		$remover = $this->c->get(RemoverFactory::class)->generateRemoverService('papers', 'downloads');

		$remover->deleteFile('papers', 'one', 'downloads', 'readme.txt');
		$remover->deleteFile('papers', 'one', 'downloads', 'Price Lists');

		expect(is_dir("{$this->datadir}/papers/one/downloads/Price Lists"))->toBeFalse()
			->and(($this->kept)('downloads', 'trash'))->toBe(['Price Lists/a.txt', 'Price Lists/b.txt', 'readme.txt']);

		$files = ($this->restore)('a.txt');

		expect(array_map(fn (array $f): string => trim("{$f['path']}/{$f['file']}", '/') . " {$f['outcome']}", $files))->toBe(['Price Lists/a.txt restored', 'Price Lists/b.txt restored'])
			->and(file_get_contents("{$this->datadir}/papers/one/downloads/Price Lists/a.txt"))->toBe('prices')
			// The snapshot is from after readme.txt was removed, so that one stays in the trash.
			->and(($this->kept)('downloads', 'trash'))->toBe(['readme.txt']);
	});

	test('a deleted object\'s gallery and depot come back from the trash', function (): void {
		($this->backups)(['trash' => true]);
		($this->seed)();
		($this->upload)('photos', 'a.jpg');
		($this->upload)('downloads', 'a.txt', contents: 'prices', subpath: 'Price Lists');

		$this->c->get(ObjectRemover::class)->deleteObject('papers', 'one');
		$store = $this->c->get(BackupStore::class);
		$this->c->get(ObjectRestorer::class)->restoreWithFiles('papers', 'one', (string)$store->latestObjectSnapshot('papers', 'one'));

		expect(($this->live)('photos'))->toBe(['a.jpg'])
			->and(file_get_contents("{$this->datadir}/papers/one/downloads/Price Lists/a.txt"))->toBe('prices');
	});
});

test('a file over the size limit is deleted, not kept', function (): void {
	($this->backups)(['keepFiles' => 2, 'trash' => true, 'maxFileSizeMB' => 1]);
	($this->seed)();
	($this->upload)('pdf', 'big.bin', contents: str_repeat('x', 1024 * 1024 + 1));
	($this->upload)('pdf', 'small.txt', contents: 'small');
	($this->upload)('pdf', 'last.txt', contents: 'last');
	($this->upload)('downloads', 'huge.bin', contents: str_repeat('x', 1024 * 1024 + 1));
	$this->c->get(RemoverFactory::class)->generateRemoverService('papers', 'downloads')->deleteFile('papers', 'one', 'downloads', 'huge.bin');

	expect(($this->kept)('pdf'))->toBe(['small.txt'])
		->and(($this->kept)('downloads', 'trash'))->toBe([])
		->and(($this->live)('downloads'))->toBe([]);
});

test('the record history honors its own settings too', function (): void {
	// `keep` and `maxAgeDays` never reached BackupStore from the container, so
	// every install kept ten snapshots whatever Settings → Backups said.
	($this->backups)(['keep' => 2]);
	($this->seed)();
	foreach (['Two', 'Three', 'Four', 'Five'] as $title) {
		$this->c->get(ObjectUpdater::class)->updateObject('papers', 'one', ['id' => 'one', 'title' => $title]);
	}

	expect($this->c->get(BackupStore::class)->listObjectSnapshots('papers', 'one'))->toHaveCount(2);
});
