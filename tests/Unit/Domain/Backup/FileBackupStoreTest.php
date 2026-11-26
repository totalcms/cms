<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Monolog\Level;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Domain\Backup\Service\FileBackupStore;
use TotalCMS\Domain\Backup\Service\FileKindResolver;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// FileBackupStore keeps files that are about to be deleted, so a restored
// record can have them back. It is told a path; it works out what each file
// under it is and applies the settings. It moves files; it never copies.
// Driven against a real filesystem in a temp dir: the store is file plumbing,
// and a mocked filesystem would test the mock.
//
// What a file is comes from FileKindResolver, which reads the schema and has
// its own test. Here it is a fixed map:
//
//   image            an image field          photos           a gallery
//   doc, pdf         file fields             downloads/**     a depot
//   lead/photo       an image in a card      team/*/photo     an image in a deck item
//                                            team/*/cv        a file in a deck item

describe('FileBackupStore', function (): void {
	beforeEach(function (): void {
		$this->root = sys_get_temp_dir() . '/tcms-file-backups-' . bin2hex(random_bytes(6));
		mkdir($this->root, 0755, true);

		$this->storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($this->root)));
		$this->logger  = new RecordingLogger();

		$this->kinds = new class extends FileKindResolver {
			public function __construct()
			{
			}

			public function kind(string $collection, string $property, string $dir): ?string
			{
				return match (true) {
					$property === 'image' && $dir === ''                             => self::IMAGE,
					in_array($property, ['doc', 'pdf'], true) && $dir === ''         => self::FILE,
					$property === 'photos' && $dir === ''                            => self::GALLERY,
					$property === 'downloads'                                        => self::DEPOT,
					$property === 'lead' && $dir === 'photo'                         => self::IMAGE,
					$property === 'team' && preg_match('#^\w+/photo$#', $dir) === 1  => self::IMAGE,
					$property === 'team' && preg_match('#^\w+/cv$#', $dir) === 1     => self::FILE,
					default                                                          => null,
				};
			}
		};

		/** @param array<string,mixed> $backups */
		$this->make = function (array $backups = [], ?StorageAdapterInterface $storage = null): FileBackupStore {
			$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
			$config->backups = $backups;

			return new FileBackupStore($storage ?? $this->storage, $this->kinds, new LoggerFactory(['level' => Level::Debug, 'test' => $this->logger]), $config);
		};

		/** Put a file where a property keeps it. `$at` is `property[/folder…]/name`. */
		$this->live = function (string $at, string $contents = 'x'): string {
			$path = "{$this->root}/blog/post-1/{$at}";
			is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
			file_put_contents($path, $contents);

			return $path;
		};

		/** Put a file in history, dated so its place in the order is known. */
		$this->keep = function (string $property, string $stamp, string $file, string $contents = 'old', string $area = 'files', string $object = 'blog/post-1'): string {
			$path = "{$this->root}/.system/backups/objects/{$object}/{$area}/{$property}/{$stamp}/{$file}";
			is_dir(dirname($path)) || mkdir(dirname($path), 0755, true);
			file_put_contents($path, $contents);

			return $path;
		};

		/** @return list<string> what is kept for a property, as `folder…/name` without the date, oldest first */
		$this->kept = function (string $property, string $area = 'files'): array {
			$base  = "{$this->root}/.system/backups/objects/blog/post-1/{$area}/{$property}";
			$found = [];
			if (is_dir($base)) {
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
					$found[] = substr((string)$file, strlen($base) + 1);
				}
			}
			sort($found);

			return array_map(fn (string $path): string => substr($path, (int)strpos($path, '/') + 1), $found);
		};

		$this->ago = fn (int $seconds): string => date('Ymd-His', time() - $seconds);
	});

	afterEach(fn () => recursiveDelete($this->root, forceComplete: true));

	describe('by default', function (): void {
		test('nothing is kept, and nothing is even looked at', function (): void {
			$files = [($this->live)('image/hero.jpg'), ($this->live)('doc/a.pdf'), ($this->live)('photos/a.jpg'), ($this->live)('downloads/a.zip')];
			$store = ($this->make)();

			foreach (['image', 'doc', 'photos', 'downloads'] as $property) {
				expect($store->setAside('blog', 'post-1', $property))->toBe([]);
			}

			expect(array_map(is_file(...), $files))->toBe([true, true, true, true])
				->and($store->active())->toBeFalse()
				->and(is_dir($this->root . '/.system'))->toBeFalse();
		});

		test('each setting switches its own kind on', function (array $settings, array $expected): void {
			$store = ($this->make)($settings);
			$files = [
				'image'   => ($this->live)('image/hero.jpg'),
				'file'    => ($this->live)('doc/a.pdf'),
				'gallery' => ($this->live)('photos/a.jpg'),
				'depot'   => ($this->live)('downloads/a.zip'),
			];
			foreach (['image', 'doc', 'photos', 'downloads'] as $property) {
				$store->setAside('blog', 'post-1', $property);
			}

			$keptKinds = array_keys(array_filter($files, fn (string $path): bool => !is_file($path)));

			expect($keptKinds)->toBe($expected);
		})->with([
			'images'             => [['keepImages' => 1], ['image']],
			'files'              => [['keepFiles' => 1], ['file']],
			'the trash'          => [['trash' => true], ['gallery', 'depot']],
			'all three'          => [['keepImages' => 1, 'keepFiles' => 1, 'trash' => true], ['image', 'file', 'gallery', 'depot']],
			'object history off' => [['enable' => false, 'keepImages' => 3, 'keepFiles' => 3, 'trash' => true], []],
		]);
	});

	describe('a field with one file (image, file)', function (): void {
		test('its file is moved into the object\'s history, under the time it was set aside', function (): void {
			$file = ($this->live)('image/hero.jpg', 'pixels');
			($this->live)('image/.cache/hero.jpg/w800.jpg', 'render');

			$moves = ($this->make)(['keepImages' => 2])->setAside('blog', 'post-1', 'image');

			$history = glob("{$this->root}/.system/backups/objects/blog/post-1/files/image/*/hero.jpg") ?: [];

			expect($history)->toHaveCount(1)
				->and(basename(dirname($history[0])))->toMatch('/^\d{8}-\d{6}$/')
				->and(file_get_contents($history[0]))->toBe('pixels')
				// Moved, not copied: a large file costs nothing to keep.
				->and(is_file($file))->toBeFalse()
				// Renders are not history; whoever deletes the property clears them.
				->and(is_file(dirname($file) . '/.cache/hero.jpg/w800.jpg'))->toBeTrue()
				->and($moves)->toHaveCount(1);
		});

		test('it keeps its newest files up to the limit and drops the oldest', function (): void {
			// The fixtures are dated, so the age rule is off here; it has its own test.
			$store = ($this->make)(['keepFiles' => 2, 'maxAgeDays' => 0]);
			($this->keep)('doc', '20260101-090000', 'v1.pdf');
			($this->keep)('doc', '20260102-090000', 'v2.pdf');
			($this->live)('doc/v3.pdf');

			$store->setAside('blog', 'post-1', 'doc');

			expect(($this->kept)('doc'))->toBe(['v2.pdf', 'v3.pdf']);
		});

		test('a kept file older than the age limit goes, whatever the count', function (): void {
			($this->keep)('doc', ($this->ago)(40 * 86400), 'old.pdf');
			($this->live)('doc/new.pdf');

			($this->make)(['keepFiles' => 10, 'maxAgeDays' => 30])->setAside('blog', 'post-1', 'doc');

			expect(($this->kept)('doc'))->toBe(['new.pdf']);
		});

		test('with the age rule off, the count alone decides', function (): void {
			($this->keep)('doc', ($this->ago)(400 * 86400), 'old.pdf');
			($this->live)('doc/new.pdf');

			($this->make)(['keepFiles' => 10, 'maxAgeDays' => 0])->setAside('blog', 'post-1', 'doc');

			expect(($this->kept)('doc'))->toBe(['old.pdf', 'new.pdf']);
		});

		test('two files set aside in the same second both survive', function (): void {
			$store = ($this->make)(['keepFiles' => 5]);

			($this->live)('doc/a.pdf', 'A');
			$store->setAside('blog', 'post-1', 'doc');
			($this->live)('doc/a.pdf', 'B');
			$store->setAside('blog', 'post-1', 'doc');

			expect(($this->kept)('doc'))->toBe(['a.pdf', 'a.pdf']);
		});

		test('an empty property, or one that is not there, writes nothing', function (): void {
			mkdir("{$this->root}/blog/post-1/image", 0755, true);
			$store = ($this->make)(['keepImages' => 3]);

			expect($store->setAside('blog', 'post-1', 'image'))->toBe([])
				->and($store->setAside('blog', 'post-1', 'nope'))->toBe([])
				->and(is_dir($this->root . '/.system'))->toBeFalse();
		});
	});

	describe('a file inside a card or a deck', function (): void {
		test('is kept where it sat, so it can go back there', function (): void {
			($this->live)('team/alice/photo/alice.jpg', 'alice');

			($this->make)(['keepImages' => 1])->setAside('blog', 'post-1', 'team', 'alice/photo');

			expect(($this->kept)('team'))->toBe(['alice/photo/alice.jpg'])
				->and(is_file("{$this->root}/blog/post-1/team/alice/photo/alice.jpg"))->toBeFalse();
		});

		test('each slot counts for itself: one item\'s uploads never push out another\'s', function (): void {
			$store = ($this->make)(['keepImages' => 1, 'maxAgeDays' => 0]);
			($this->keep)('team', '20260101-090000', 'alice/photo/a1.jpg');
			($this->keep)('team', '20260102-090000', 'bob/photo/b1.jpg');
			($this->live)('team/alice/photo/a2.jpg');

			$store->setAside('blog', 'post-1', 'team', 'alice/photo');

			expect(($this->kept)('team'))->toBe(['bob/photo/b1.jpg', 'alice/photo/a2.jpg']);
		});

		test('a whole item going takes each of its files by that file\'s own rule', function (): void {
			// Images on, files off: the photo is kept, the CV is left to be deleted.
			($this->live)('team/alice/photo/alice.jpg');
			$cv = ($this->live)('team/alice/cv/alice.pdf');
			($this->live)('team/bob/photo/bob.jpg');

			($this->make)(['keepImages' => 1])->setAside('blog', 'post-1', 'team', 'alice');

			expect(($this->kept)('team'))->toBe(['alice/photo/alice.jpg'])
				->and(is_file($cv))->toBeTrue()
				->and(is_file("{$this->root}/blog/post-1/team/bob/photo/bob.jpg"))->toBeTrue();
		});

		test('a whole deck going is one dated folder that mirrors it', function (): void {
			($this->live)('team/alice/photo/alice.jpg');
			($this->live)('team/alice/cv/alice.pdf');
			($this->live)('team/bob/photo/bob.jpg');

			($this->make)(['keepImages' => 1, 'keepFiles' => 1])->setAside('blog', 'post-1', 'team');

			expect(($this->kept)('team'))->toBe(['alice/cv/alice.pdf', 'alice/photo/alice.jpg', 'bob/photo/bob.jpg'])
				->and(glob("{$this->root}/.system/backups/objects/blog/post-1/files/team/*", GLOB_ONLYDIR))->toHaveCount(1);
		});
	});

	describe('the trash (gallery, depot)', function (): void {
		test('one picture taken out of a gallery goes to the trash; the others stay', function (): void {
			($this->live)('photos/a.jpg', 'A');
			$other = ($this->live)('photos/b.jpg');

			($this->make)(['trash' => true])->setAside('blog', 'post-1', 'photos', null, 'a.jpg');

			expect(($this->kept)('photos', 'trash'))->toBe(['a.jpg'])
				->and(is_file($other))->toBeTrue()
				// The trash is its own tree: it is never counted against a field's earlier files.
				->and(is_dir("{$this->root}/.system/backups/objects/blog/post-1/files"))->toBeFalse();
		});

		test('a depot folder goes with everything in it, folders kept as they were', function (): void {
			($this->live)('downloads/Price Lists/2026/a.pdf');
			($this->live)('downloads/Price Lists/b.pdf');
			$kept = ($this->live)('downloads/readme.txt');

			($this->make)(['trash' => true])->setAside('blog', 'post-1', 'downloads', null, 'Price Lists');

			expect(($this->kept)('downloads', 'trash'))->toBe(['Price Lists/2026/a.pdf', 'Price Lists/b.pdf'])
				->and(is_file($kept))->toBeTrue();
		});

		test('a file in a depot folder is found by its folder and its name', function (): void {
			($this->live)('downloads/Price Lists/a.pdf');

			($this->make)(['trash' => true])->setAside('blog', 'post-1', 'downloads', 'Price Lists', 'a.pdf');

			expect(($this->kept)('downloads', 'trash'))->toBe(['Price Lists/a.pdf']);
		});

		test('the trash has no count: every removed file is kept until it is too old', function (): void {
			$store = ($this->make)(['trash' => true, 'keepImages' => 1, 'maxAgeDays' => 30]);
			foreach (['a', 'b', 'c', 'd'] as $name) {
				($this->live)("photos/{$name}.jpg");
				$store->setAside('blog', 'post-1', 'photos', null, "{$name}.jpg");
			}
			($this->keep)('photos', ($this->ago)(40 * 86400), 'ancient.jpg', area: 'trash');
			($this->live)('photos/e.jpg');
			$store->setAside('blog', 'post-1', 'photos', null, 'e.jpg');

			$kept = ($this->kept)('photos', 'trash');
			sort($kept);

			expect($kept)->toBe(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg']);
		});

		test('the trash always empties: with the age rule off it is kept for 30 days', function (): void {
			($this->keep)('photos', ($this->ago)(40 * 86400), 'ancient.jpg', area: 'trash');
			($this->keep)('photos', ($this->ago)(20 * 86400), 'recent.jpg', area: 'trash');
			($this->live)('photos/new.jpg');

			($this->make)(['trash' => true, 'maxAgeDays' => 0])->setAside('blog', 'post-1', 'photos', null, 'new.jpg');

			$kept = ($this->kept)('photos', 'trash');
			sort($kept);

			expect($kept)->toBe(['new.jpg', 'recent.jpg']);
		});
	});

	describe('the size limit', function (): void {
		test('a file over the limit is not kept: it is left for the delete that follows', function (string $at, string $property, array $settings): void {
			$big   = ($this->live)($at, str_repeat('x', 1024 * 1024 + 1));
			$small = ($this->live)(dirname($at) . '/small.bin', 'x');

			($this->make)($settings + ['maxFileSizeMB' => 1])->setAside('blog', 'post-1', $property);

			expect(is_file($big))->toBeTrue()
				->and(is_file($small))->toBeFalse();
		})->with([
			'in a file field' => ['doc/big.bin', 'doc', ['keepFiles' => 2]],
			'in a depot'      => ['downloads/big.bin', 'downloads', ['trash' => true]],
		]);

		test('the limit is 100 MB unless set, and 0 means none', function (): void {
			$file = ($this->live)('doc/a.bin', str_repeat('x', 2 * 1024 * 1024));

			($this->make)(['keepFiles' => 1])->setAside('blog', 'post-1', 'doc');
			expect(is_file($file))->toBeFalse();

			$file = ($this->live)('doc/b.bin', str_repeat('x', 2 * 1024 * 1024));
			($this->make)(['keepFiles' => 1, 'maxFileSizeMB' => 0])->setAside('blog', 'post-1', 'doc');
			expect(is_file($file))->toBeFalse()
				->and(FileBackupStore::DEFAULT_MAX_FILE_MB)->toBe(100);
		});
	});

	describe('undo', function (): void {
		test('puts back what a set-aside moved, for a delete that then failed', function (): void {
			$store = ($this->make)(['keepImages' => 1, 'keepFiles' => 1]);
			($this->live)('team/alice/photo/alice.jpg', 'alice');
			($this->live)('team/alice/cv/alice.pdf', 'cv');

			$moves = $store->setAside('blog', 'post-1', 'team');
			$store->undo($moves);

			expect(file_get_contents("{$this->root}/blog/post-1/team/alice/photo/alice.jpg"))->toBe('alice')
				->and(file_get_contents("{$this->root}/blog/post-1/team/alice/cv/alice.pdf"))->toBe('cv')
				// No empty dated folder is left to be listed as a version.
				->and(glob("{$this->root}/.system/backups/objects/blog/post-1/files/team/*"))->toBe([]);
		});
	});

	describe('the age sweep', function (): void {
		test('clears kept files and trash past their age everywhere, deleted objects included', function (): void {
			// A deleted object never sets another file aside, so nothing would
			// ever look at its kept files again. The age rule has to reach them.
			$oldFile  = ($this->keep)('pdf', ($this->ago)(40 * 86400), 'old.pdf', object: 'news/gone');
			$newFile  = ($this->keep)('pdf', ($this->ago)(86400), 'recent.pdf', object: 'news/gone');
			$oldTrash = ($this->keep)('photos', ($this->ago)(40 * 86400), 'old.jpg', area: 'trash', object: 'news/gone');
			// An object with snapshots and no kept files is passed over.
			mkdir("{$this->root}/.system/backups/objects/news/plain", 0755, true);
			file_put_contents("{$this->root}/.system/backups/objects/news/plain/plain-20200101-000000.json", '{}');

			$store = ($this->make)(['maxAgeDays' => 30]);

			expect($store->sweep())->toBe(2)
				->and(is_file($oldFile))->toBeFalse()
				->and(is_file($oldTrash))->toBeFalse()
				->and(is_file($newFile))->toBeTrue()
				->and(is_file("{$this->root}/.system/backups/objects/news/plain/plain-20200101-000000.json"))->toBeTrue();
		});

		test('runs at most once a day, however often it is asked', function (): void {
			$store = ($this->make)(['maxAgeDays' => 30]);
			($this->keep)('pdf', ($this->ago)(86400), 'recent.pdf', object: 'news/gone');
			$store->sweep();

			$late = ($this->keep)('pdf', ($this->ago)(40 * 86400), 'old.pdf', object: 'news/gone');

			expect($store->sweep())->toBe(0)
				->and(is_file($late))->toBeTrue();
		});

		test('a set-aside triggers it, so a site with no cron still clears old files', function (): void {
			$old = ($this->keep)('pdf', ($this->ago)(40 * 86400), 'old.pdf', object: 'news/gone');
			($this->live)('doc/a.pdf');

			($this->make)(['keepFiles' => 5, 'maxAgeDays' => 30])->setAside('blog', 'post-1', 'doc');

			expect(is_file($old))->toBeFalse();
		});

		test('with the age rule off, earlier files stay and only the trash empties', function (): void {
			$file  = ($this->keep)('pdf', ($this->ago)(400 * 86400), 'old.pdf', object: 'news/gone');
			$trash = ($this->keep)('photos', ($this->ago)(400 * 86400), 'old.jpg', area: 'trash', object: 'news/gone');

			($this->make)(['maxAgeDays' => 0])->sweep();

			expect(is_file($file))->toBeTrue()
				->and(is_file($trash))->toBeFalse();
		});

		test('a site with no backups folder is left without one', function (): void {
			expect(($this->make)()->sweep())->toBe(0)
				->and(is_dir($this->root . '/.system'))->toBeFalse();
		});
	});

	describe('versions', function (): void {
		test('lists everything kept for an object, with where it sat and whether it is trash', function (): void {
			$store = ($this->make)();
			($this->keep)('doc', '20260101-090000', 'v1.pdf', '12345');
			($this->keep)('doc', '20260301-101500-1', 'v2.pdf', '123');
			($this->keep)('team', '20260201-090000', 'alice/photo/alice.jpg', '1');
			($this->keep)('downloads', '20260201-090000', 'Price Lists/a.pdf', '12', area: 'trash');

			$versions = $store->versions('blog', 'post-1');

			expect(array_map(fn (array $v): string => trim("{$v['property']}/{$v['path']}", '/') . "/{$v['name']}" . ($v['trash'] ? ' (trash)' : ''), $versions))->toBe([
				'doc/v2.pdf',
				'doc/v1.pdf',
				'team/alice/photo/alice.jpg',
				'downloads/Price Lists/a.pdf (trash)',
			])
				->and(array_column($versions, 'size'))->toBe([3, 5, 1, 2])
				->and($versions[1]['at'])->toStartWith('2026-01-01T09:00:00')
				->and($store->versions('blog', 'post-2'))->toBe([]);
		});
	});

	describe('reconcile: after a record is restored, make a field\'s file the one it names', function (): void {
		beforeEach(function (): void {
			$this->liveNames = fn (string $dir): array => array_map(basename(...), array_filter(glob("{$this->root}/blog/post-1/{$dir}/*") ?: [], is_file(...)));
		});

		test('the named file is already there: nothing moves', function (): void {
			($this->live)('image/hero.jpg');
			($this->keep)('image', '20260101-090000', 'older.jpg');

			expect(($this->make)(['keepImages' => 3])->reconcile('blog', 'post-1', 'image', '', 'hero.jpg', null))->toBe('present')
				->and(($this->liveNames)('image'))->toBe(['hero.jpg'])
				->and(($this->kept)('image'))->toBe(['older.jpg']);
		});

		test('the named file is in history: it comes back, and the file it displaces is kept', function (): void {
			($this->live)('image/newer.jpg', 'new pixels');
			($this->keep)('image', '20260101-090000', 'hero.jpg', 'old pixels');
			($this->live)('image/.cache/newer.jpg/w800.jpg', 'a render');

			$outcome = ($this->make)(['keepImages' => 3, 'maxAgeDays' => 0])->reconcile('blog', 'post-1', 'image', '', 'hero.jpg', null);

			expect($outcome)->toBe('restored')
				->and(($this->liveNames)('image'))->toBe(['hero.jpg'])
				->and(file_get_contents("{$this->root}/blog/post-1/image/hero.jpg"))->toBe('old pixels')
				// Undoing the restore is another restore: the displaced file is there for it.
				->and(($this->kept)('image'))->toBe(['newer.jpg'])
				// Renders may be of another file that had this name.
				->and(is_dir("{$this->root}/blog/post-1/image/.cache"))->toBeFalse();
		});

		test('a restore never destroys the file it displaces, even with history switched off', function (): void {
			// The kept file is from when history was on; the setting is zero now.
			($this->live)('image/newer.jpg', 'new pixels');
			($this->keep)('image', ($this->ago)(3600), 'hero.jpg', 'old pixels');

			expect(($this->make)()->reconcile('blog', 'post-1', 'image', '', 'hero.jpg', null))->toBe('restored')
				->and(($this->liveNames)('image'))->toBe(['hero.jpg'])
				->and(($this->kept)('image'))->toBe(['newer.jpg']);
		});

		test('nor is the displaced file refused for its size', function (): void {
			($this->live)('doc/newer.bin', str_repeat('x', 1024 * 1024 + 1));
			($this->keep)('doc', ($this->ago)(3600), 'a.bin', 'old');

			expect(($this->make)(['keepFiles' => 1, 'maxFileSizeMB' => 1])->reconcile('blog', 'post-1', 'doc', '', 'a.bin', null))->toBe('restored')
				->and(($this->kept)('doc'))->toBe(['newer.bin']);
		});

		test('a file replaced by one of the same name: the size the record names tells them apart', function (): void {
			// The commonest replace there is: a corrected brochure.pdf over brochure.pdf.
			($this->live)('pdf/brochure.pdf', 'the corrected one');
			($this->keep)('pdf', ($this->ago)(60), 'brochure.pdf', 'original');
			$store = ($this->make)(['keepFiles' => 3]);

			expect($store->reconcile('blog', 'post-1', 'pdf', '', 'brochure.pdf', strlen('original')))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/pdf/brochure.pdf"))->toBe('original')
				->and(($this->kept)('pdf'))->toBe(['brochure.pdf']);

			// And back again: the snapshot of the corrected record names the other size.
			expect($store->reconcile('blog', 'post-1', 'pdf', '', 'brochure.pdf', strlen('the corrected one')))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/pdf/brochure.pdf"))->toBe('the corrected one');
		});

		test('same name, another size, but no kept file of that size: the live file stays', function (): void {
			($this->live)('pdf/brochure.pdf', 'the corrected one');
			($this->keep)('pdf', ($this->ago)(60), 'brochure.pdf', 'some other version');

			expect(($this->make)(['keepFiles' => 3])->reconcile('blog', 'post-1', 'pdf', '', 'brochure.pdf', 8))->toBe('present')
				->and(file_get_contents("{$this->root}/blog/post-1/pdf/brochure.pdf"))->toBe('the corrected one')
				->and(($this->kept)('pdf'))->toBe(['brochure.pdf']);
		});

		test('when a name was used twice, the one whose size the record names comes back; else the newest', function (): void {
			$store = ($this->make)(['keepImages' => 5, 'maxAgeDays' => 0]);
			($this->keep)('image', '20260101-090000', 'hero.jpg', 'first');
			($this->keep)('image', '20260201-090000', 'hero.jpg', 'the second');

			expect($store->reconcile('blog', 'post-1', 'image', '', 'hero.jpg', 5))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/image/hero.jpg"))->toBe('first');

			unlink("{$this->root}/blog/post-1/image/hero.jpg");
			($this->keep)('image', '20260101-090000', 'hero.jpg', 'first');

			expect($store->reconcile('blog', 'post-1', 'image', '', 'hero.jpg', 999))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/image/hero.jpg"))->toBe('the second');
		});

		test('a deleted object\'s file comes back into a folder that is no longer there', function (): void {
			($this->keep)('pdf', ($this->ago)(60), 'brochure.pdf', 'pages');

			expect(($this->make)(['keepFiles' => 1])->reconcile('blog', 'post-1', 'pdf', '', 'brochure.pdf', 5))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/pdf/brochure.pdf"))->toBe('pages')
				->and(glob("{$this->root}/.system/backups/objects/blog/post-1/files/pdf/*"))->toBe([]);
		});

		test('the named file is nowhere: it is reported missing and the live file is left alone', function (): void {
			($this->live)('image/newer.jpg');

			expect(($this->make)(['keepImages' => 3])->reconcile('blog', 'post-1', 'image', '', 'hero.jpg', null))->toBe('missing')
				->and(($this->liveNames)('image'))->toBe(['newer.jpg'])
				->and(($this->kept)('image'))->toBe([]);
		});

		test('the record names no file: a live file is set aside when history is on, and left when it is off', function (): void {
			($this->live)('image/newer.jpg');

			// Off: the file stays where it is, unreferenced, as before.
			expect(($this->make)()->reconcile('blog', 'post-1', 'image', '', '', null))->toBe('none')
				->and(($this->liveNames)('image'))->toBe(['newer.jpg']);

			// On: the record has no file, so neither does the field; it is one restore away.
			expect(($this->make)(['keepImages' => 2])->reconcile('blog', 'post-1', 'image', '', '', null))->toBe('none')
				->and(($this->liveNames)('image'))->toBe([])
				->and(($this->kept)('image'))->toBe(['newer.jpg']);
		});

		test('a file inside a deck item comes back to its own slot, from a whole deck that was set aside', function (): void {
			$stamp = ($this->ago)(60);
			($this->keep)('team', $stamp, 'alice/photo/alice.jpg', 'alice');
			($this->keep)('team', $stamp, 'bob/photo/bob.jpg', 'bob');
			($this->live)('team/alice/photo/other.jpg', 'someone else');
			$store = ($this->make)(['keepImages' => 1]);

			expect($store->reconcile('blog', 'post-1', 'team', 'alice/photo', 'alice.jpg', null))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/team/alice/photo/alice.jpg"))->toBe('alice')
				->and(($this->liveNames)('team/alice/photo'))->toBe(['alice.jpg'])
				// Bob's is still kept, in the same dated folder; the displaced file is kept in a new one.
				->and(($this->kept)('team'))->toBe(['bob/photo/bob.jpg', 'alice/photo/other.jpg']);
		});

		test('a gallery picture comes back from the trash; pictures already there are not touched', function (): void {
			($this->keep)('photos', ($this->ago)(60), 'a.jpg', 'A', area: 'trash');
			($this->live)('photos/b.jpg', 'B');
			$store = ($this->make)(['trash' => true]);

			expect($store->reconcile('blog', 'post-1', 'photos', '', 'a.jpg', null))->toBe('restored')
				->and($store->reconcile('blog', 'post-1', 'photos', '', 'b.jpg', null))->toBe('present')
				->and($store->reconcile('blog', 'post-1', 'photos', '', 'never.jpg', null))->toBe('missing');

			$live = ($this->liveNames)('photos');
			sort($live);

			expect($live)->toBe(['a.jpg', 'b.jpg'])
				->and(($this->kept)('photos', 'trash'))->toBe([]);
		});

		test('a depot file comes back to its folder', function (): void {
			($this->keep)('downloads', ($this->ago)(60), 'Price Lists/2026/a.pdf', 'prices', area: 'trash');

			expect(($this->make)(['trash' => true])->reconcile('blog', 'post-1', 'downloads', 'Price Lists/2026', 'a.pdf', null))->toBe('restored')
				->and(file_get_contents("{$this->root}/blog/post-1/downloads/Price Lists/2026/a.pdf"))->toBe('prices');
		});

		test('a place that keeps no files is none of its business', function (): void {
			($this->live)('title/stray.txt');

			expect(($this->make)(['keepFiles' => 3, 'keepImages' => 3, 'trash' => true])->reconcile('blog', 'post-1', 'title', '', 'stray.txt', null))->toBe('none');
		});
	});

	describe('state: can a file a record names still be had, without moving anything', function (): void {
		test('says live, kept, gone, or none for a place that holds no files', function (): void {
			$store = ($this->make)();
			($this->live)('image/hero.jpg', 'pixels');
			($this->keep)('image', ($this->ago)(60), 'older.jpg');
			($this->keep)('team', ($this->ago)(60), 'alice/photo/alice.jpg');
			($this->keep)('downloads', ($this->ago)(60), 'Price Lists/a.pdf', area: 'trash');
			$before = ($this->kept)('image');

			expect($store->state('blog', 'post-1', 'image', '', 'hero.jpg', null))->toBe('live')
				->and($store->state('blog', 'post-1', 'image', '', 'older.jpg', null))->toBe('kept')
				->and($store->state('blog', 'post-1', 'image', '', 'never.jpg', null))->toBe('gone')
				->and($store->state('blog', 'post-1', 'team', 'alice/photo', 'alice.jpg', null))->toBe('kept')
				->and($store->state('blog', 'post-1', 'downloads', 'Price Lists', 'a.pdf', null))->toBe('kept')
				->and($store->state('blog', 'post-1', 'title', '', 'x.txt', null))->toBe('none')
				->and($store->state('blog', 'post-1', 'image', '', '', null))->toBe('none')
				->and($store->state('blog', 'post-1', 'image', '', '../x.jpg', null))->toBe('gone')
				// Asking changes nothing.
				->and(($this->kept)('image'))->toBe($before)
				->and(is_file("{$this->root}/blog/post-1/image/hero.jpg"))->toBeTrue();
		});

		test('a live file of the same name but another size is not the one meant', function (): void {
			$store = ($this->make)();
			($this->live)('pdf/brochure.pdf', 'the corrected one');
			($this->keep)('pdf', ($this->ago)(60), 'brochure.pdf', 'original');

			expect($store->state('blog', 'post-1', 'pdf', '', 'brochure.pdf', strlen('the corrected one')))->toBe('live')
				->and($store->state('blog', 'post-1', 'pdf', '', 'brochure.pdf', strlen('original')))->toBe('kept')
				// No kept file of that size: the live one is all there is.
				->and($store->state('blog', 'post-1', 'pdf', '', 'brochure.pdf', 3))->toBe('live');
		});
	});

	describe('safety', function (): void {
		test('ids that could step outside the backup tree are refused', function (string $collection, string $id, string $property): void {
			$store = ($this->make)(['keepFiles' => 5, 'keepImages' => 5, 'trash' => true]);

			expect($store->setAside($collection, $id, $property))->toBe([])
				->and($store->versions($collection, $id))->toBe([])
				->and($store->reconcile($collection, $id, $property, '', 'a.pdf', null))->toBe('none')
				->and(is_dir($this->root . '/.system'))->toBeFalse();
		})->with([['..', 'post-1', 'doc'], ['blog', '../x', 'doc'], ['blog', 'post-1', '..'], ['blog', 'post-1', 'a/b'], ['', 'post-1', 'doc']]);

		test('a file name with a path in it is never put back', function (): void {
			($this->keep)('doc', ($this->ago)(60), 'secret.pdf');

			expect(($this->make)(['keepFiles' => 5])->reconcile('blog', 'post-1', 'doc', '', '../../secret.pdf', null))->toBe('missing');
		});

		test('a storage failure is logged and swallowed: history must never fail the save it rides on', function (): void {
			$broken = $this->createMock(StorageAdapterInterface::class);
			$broken->method('fileExists')->willReturn(false);
			$broken->method('directoryExists')->willReturn(true);
			$broken->method('listFiles')->willThrowException(new RuntimeException('disk gone'));
			$broken->method('listDirectories')->willThrowException(new RuntimeException('disk gone'));
			$store = ($this->make)(['keepFiles' => 5], $broken);

			expect($store->setAside('blog', 'post-1', 'doc'))->toBe([])
				->and($store->reconcile('blog', 'post-1', 'doc', '', 'a.pdf', null))->toBe('missing')
				->and($store->versions('blog', 'post-1'))->toBe([])
				->and($store->sweep())->toBe(0)
				->and($this->logger->records)->not->toBe([]);
		});
	});
});
