<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Backup\Service\FileBackupStore;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\JsonData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DeckFileCleaner;
use TotalCMS\Domain\Property\Service\DepotRemover;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// Every place a property's files are deleted. At each one the file history is
// told first, with the same path the delete is about to take, because the
// event that feeds the record history fires too late: by then the file is
// gone. What it keeps of that path is its own business (FileBackupStoreTest);
// these tests are about being told, about what, and in what order.

describe('file history hooks', function (): void {
	beforeEach(function (): void {
		$this->order = [];

		$this->history = $this->createMock(FileBackupStore::class);
		$this->history->method('active')->willReturn(true);
		$this->history->method('setAside')->willReturnCallback(function (string $collection, string $id, string $property, ?string $subpath = null, ?string $name = null): array {
			$this->order[] = 'setAside ' . implode('/', array_filter([$collection, $id, $property, $subpath, $name], fn (?string $part): bool => $part !== null));

			return [['kept' => "kept/{$property}", 'live' => "live/{$property}", 'version' => 'v']];
		});

		$this->storage = $this->createMock(PropertyRepository::class);
		$this->storage->method('directoryExists')->willReturn(true);
		$this->storage->method('deleteDirectory')->willReturnCallback(function (string $c, string $id, string $property, ?string $name = null, ?string $subpath = null): void {
			$this->order[] = 'deleteDirectory ' . implode('/', array_filter([$property, $subpath, $name], fn (?string $part): bool => $part !== null));
		});
		$this->storage->method('deleteFile')->willReturnCallback(function (string $c, string $id, string $property, string $name, ?string $subpath = null): void {
			$this->order[] = 'deleteFile ' . implode('/', array_filter([$property, $subpath, $name], fn (?string $part): bool => $part !== null));
		});

		$this->saved = function (): ObjectData {
			$this->order[] = 'record saved';

			return new ObjectData('post-1', []);
		};
	});

	describe('an upload that replaces a file', function (): void {
		beforeEach(function (): void {
			/** @param class-string<FileSaver> $class */
			$this->saver = function (string $class, bool $history = true): FileSaver {
				$objects = $this->createMock(ObjectFetcher::class);
				$objects->method('existsObject')->willReturn(true);
				$properties = $this->createMock(PropertyFetcher::class);
				$properties->method('fetchProperty')->willReturn(new ImageData([]));
				// The save stops at the upload itself: everything this test cares
				// about has happened by then.
				$this->storage->method('saveFile')->willThrowException(new RuntimeException('stop here'));

				$saver = new $class($this->storage, $properties, $this->createMock(ObjectSaver::class), $this->createMock(ObjectPatcher::class), $objects, $this->createMock(LoggerFactory::class), $this->createMock(Config::class));
				if ($history) {
					$saver->setFileBackups($this->history);
				}

				return $saver;
			};
		});

		test('sets the old one aside before deleting it', function (string $class): void {
			expect(fn () => ($this->saver)($class)->save('blog', 'post-1', 'cover', '/tmp/does-not-matter.bin'))->toThrow(RuntimeException::class, 'stop here')
				->and($this->order)->toBe(['setAside blog/post-1/cover', 'deleteDirectory cover']);
		})->with([FileSaver::class, ImageSaver::class]);

		test('inside a card or a deck item, it is that slot that is set aside', function (string $class): void {
			expect(fn () => ($this->saver)($class)->save('blog', 'post-1', 'team', '/tmp/does-not-matter.bin', 'alice/photo'))->toThrow(RuntimeException::class)
				->and($this->order)->toBe(['setAside blog/post-1/team/alice/photo', 'deleteDirectory team/alice/photo']);
		})->with([FileSaver::class, ImageSaver::class]);

		test('a saver with no history store works as it always did', function (): void {
			expect(fn () => ($this->saver)(FileSaver::class, history: false)->save('blog', 'post-1', 'cover', '/tmp/x.bin'))->toThrow(RuntimeException::class)
				->and($this->order)->toBe(['deleteDirectory cover']);
		});
	});

	describe('removing a file', function (): void {
		beforeEach(function (): void {
			$this->properties = $this->createMock(PropertyFetcher::class);
			$this->patcher    = $this->createMock(ObjectPatcher::class);
			$this->objects    = $this->createMock(ObjectFetcher::class);
			$this->objects->method('existsObject')->willReturn(true);

			$this->remover = new FileRemover($this->storage, $this->properties, $this->patcher, $this->objects);
			$this->remover->setFileBackups($this->history);
		});

		test('from an image or file field: the record is emptied first, then the file is set aside instead of lost', function (): void {
			$this->properties->method('fetchProperty')->willReturn(new ImageData(['name' => 'hero.jpg']));
			$this->patcher->method('patchObject')->willReturnCallback($this->saved);

			$this->remover->deleteFile('blog', 'post-1', 'cover', 'hero.jpg');

			expect($this->order)->toBe(['record saved', 'setAside blog/post-1/cover', 'deleteFile cover/hero.jpg']);
		});

		test('one picture out of a gallery: that picture alone', function (): void {
			$this->properties->method('fetchProperty')->willReturn(new GalleryData([['name' => 'a.jpg'], ['name' => 'b.jpg']]));
			$this->patcher->method('patchObject')->willReturnCallback($this->saved);

			$this->remover->deleteFile('blog', 'post-1', 'photos', 'a.jpg');

			expect($this->order)->toBe(['record saved', 'setAside blog/post-1/photos/a.jpg', 'deleteFile photos/a.jpg']);
		});

		test('a file or a folder out of a depot, in the folder it is in', function (): void {
			$this->properties->method('fetchProperty')->willReturn(new DepotData(['files' => [
				['name' => 'Price Lists', 'mime' => 'folder', 'files' => [['name' => 'a.pdf', 'mime' => 'application/pdf']]],
			]]));
			$this->patcher->method('patchObject')->willReturnCallback($this->saved);
			$remover = new DepotRemover($this->storage, $this->properties, $this->patcher, $this->objects);
			$remover->setFileBackups($this->history);

			$remover->deleteFile('blog', 'post-1', 'downloads', 'a.pdf', 'Price Lists');

			expect($this->order)->toBe(['record saved', 'setAside blog/post-1/downloads/Price Lists/a.pdf', 'deleteDirectory downloads/Price Lists/a.pdf', 'deleteFile downloads/Price Lists/a.pdf']);
		});

		test('a save that is refused leaves the file where it is, with nothing set aside', function (): void {
			$this->properties->method('fetchProperty')->willReturn(new ImageData(['name' => 'hero.jpg']));
			$this->patcher->method('patchObject')->willThrowException(new DomainException('refused'));

			expect(fn () => $this->remover->deleteFile('blog', 'post-1', 'cover', 'hero.jpg'))->toThrow(DomainException::class)
				->and($this->order)->toBe([]);
		});
	});

	test('a deck item that is gone from the record: its folder is set aside, then deleted', function (): void {
		$schema             = new SchemaData();
		$schema->properties = ['team' => ['$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'field' => 'deck']];
		$schemas            = $this->createMock(SchemaFetcher::class);
		$schemas->method('fetchSchemaForCollection')->willReturn($schema);
		$loggers = $this->createMock(LoggerFactory::class);
		$loggers->method('channelLogger')->willReturn(new NullLogger());

		(new DeckFileCleaner($schemas, $this->storage, $loggers, $this->history))->cleanup(
			'blog',
			'post-1',
			new ObjectData('post-1', ['team' => new DeckData(['alice' => ['id' => 'alice'], 'bob' => ['id' => 'bob']])]),
			new ObjectData('post-1', ['team' => new DeckData(['bob' => ['id' => 'bob']])]),
		);

		expect($this->order)->toBe(['setAside blog/post-1/team/alice', 'deleteDirectory team/alice']);
	});

	describe('ObjectRemover', function (): void {
		beforeEach(function (): void {
			$this->objects = $this->createMock(ObjectRepository::class);
			$this->fetcher = $this->createMock(ObjectFetcher::class);
			$this->updater = $this->createMock(ObjectUpdater::class);

			$this->object = new ObjectData('post-1', [
				'title' => new JsonData('Hello'),
				'cover' => new ImageData(['name' => 'hero.jpg']),
				'team'  => new DeckData(['alice' => ['id' => 'alice']]),
			]);

			$this->remover = fn (bool $deletes = true): ObjectRemover => (function () use ($deletes): ObjectRemover {
				$this->objects->method('deleteObject')->willReturnCallback(function () use ($deletes): bool {
					$this->order[] = 'object deleted';

					return $deletes;
				});

				return new ObjectRemover($this->storage, $this->objects, $this->fetcher, $this->updater, new EventDispatcher(new NullLogger()), systemWriteGate(), $this->history);
			})();
		});

		test('deleting an object sets aside every property of it before the object goes', function (): void {
			$this->objects->method('fetchObject')->willReturn($this->object);

			($this->remover)()->deleteObject('blog', 'post-1');

			expect($this->order)->toBe(['setAside blog/post-1/title', 'setAside blog/post-1/cover', 'setAside blog/post-1/team', 'object deleted']);
		});

		test('when the delete fails, what was set aside is put back', function (): void {
			$this->objects->method('fetchObject')->willReturn($this->object);
			$this->history->expects($this->once())->method('undo')->with($this->callback(fn (array $moves): bool => array_column($moves, 'live') === ['live/title', 'live/cover', 'live/team']));

			expect(($this->remover)(deletes: false)->deleteObject('blog', 'post-1'))->toBeFalse();
		});

		test('an object that cannot be read is deleted as before, with nothing to set aside', function (): void {
			$this->objects->method('fetchObject')->willReturn(null);

			($this->remover)()->deleteObject('blog', 'post-1');

			expect($this->order)->toBe(['object deleted']);
		});

		test('clearing a property saves the record, then sets its files aside', function (): void {
			$this->fetcher->method('fetchObject')->willReturn($this->object);
			$this->updater->method('updateObject')->willReturnCallback($this->saved);

			($this->remover)()->deleteObjectProperty('blog', 'post-1', 'cover');

			expect($this->order)->toBe(['record saved', 'setAside blog/post-1/cover', 'deleteDirectory cover']);
		});

		test('clearing a file inside a card or a deck item does the same for that slot', function (): void {
			$this->fetcher->method('fetchObject')->willReturn($this->object);
			$this->updater->method('updateObject')->willReturnCallback($this->saved);

			($this->remover)()->deleteNestedProperty('blog', 'post-1', 'team', 'alice/photo');

			expect($this->order)->toBe(['record saved', 'setAside blog/post-1/team/alice/photo', 'deleteDirectory team/alice/photo']);
		});

		test('a refused save leaves the files alone', function (): void {
			$this->fetcher->method('fetchObject')->willReturn($this->object);
			$this->updater->method('updateObject')->willThrowException(new DomainException('refused'));

			expect(fn () => ($this->remover)()->deleteObjectProperty('blog', 'post-1', 'cover'))->toThrow(DomainException::class)
				->and(fn () => ($this->remover)()->deleteNestedProperty('blog', 'post-1', 'team', 'alice/photo'))->toThrow(DomainException::class)
				->and($this->order)->toBe([]);
		});
	});
});
