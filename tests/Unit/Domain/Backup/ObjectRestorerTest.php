<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Backup;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Backup\Service\FileBackupStore;
use TotalCMS\Domain\Backup\Service\ObjectRestorer;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\JsonData;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * A restore is an ordinary update: decode the snapshot, hand it to
 * ObjectUpdater. The codec is final and dependency-free, so it runs for
 * real — the point of these tests is that a .json and a .md snapshot both
 * reach the updater as the same decoded shape.
 */
final class ObjectRestorerTest extends TestCase
{
	private ObjectRestorer $restorer;
	private MockObject $store;
	private MockObject $updater;
	private MockObject $schemaFetcher;
	private MockObject $files;
	private MockObject $objects;

	protected function setUp(): void
	{
		$this->store         = $this->createMock(BackupStore::class);
		$this->updater       = $this->createMock(ObjectUpdater::class);
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->files         = $this->createMock(FileBackupStore::class);
		$this->objects       = $this->createMock(ObjectFetcher::class);

		$this->restorer = new ObjectRestorer(
			$this->store,
			$this->updater,
			new ObjectFileCodec(),
			$this->schemaFetcher,
			$this->files,
			$this->objects,
		);
	}

	public function testRestoresAJsonSnapshotThroughTheUpdater(): void
	{
		$this->store->method('readObjectSnapshot')
			->with('posts', 'post-1', 'post-1-20240105-120000.json')
			->willReturn('{"id":"post-1","title":"Old title"}');

		$restored = new ObjectData('post-1', []);
		$this->updater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'post-1', ['id' => 'post-1', 'title' => 'Old title'])
			->willReturn($restored);

		$result = $this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.json');

		expect($result)->toBe($restored);
	}

	public function testRestoresAMarkdownSnapshotWithItsBodyInTheContentProperty(): void
	{
		// Sync keeps the on-disk file, which for a markdown collection is
		// frontmatter + body. The body must land back in `content`, which the
		// codec only does when the schema says `content` is a string field.
		$schema             = $this->createMock(SchemaData::class);
		$schema->properties = ['content' => ['field' => 'markdown']];
		$this->schemaFetcher->method('fetchSchemaForCollection')->with('posts')->willReturn($schema);

		$this->store->method('readObjectSnapshot')
			->willReturn("---\nid: post-1\ntitle: Old title\n---\n\nThe body.\n");

		$this->updater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'post-1', $this->callback(function (array $data): bool {
				return $data['id'] === 'post-1'
					&& $data['title'] === 'Old title'
					&& trim((string)$data['content']) === 'The body.';
			}))
			->willReturn(new ObjectData('post-1', []));

		$this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.md');
	}

	public function testAfterTheRecordIsRestoredEveryFileItNamesIsMadeToMatchIt(): void
	{
		// Record first, files second: a restore the save refuses must move nothing.
		$order = [];
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"post-1"}');
		$this->updater->method('updateObject')->willReturnCallback(function () use (&$order): ObjectData {
			$order[] = 'record saved';

			$image = fn (string $name, int $size = 0): array => ['name' => $name, 'mime' => 'image/jpeg', 'size' => $size, 'credit' => ['name' => 'Someone']];

			return new ObjectData('post-1', [
				'title'     => new JsonData('Hello'),
				'cover'     => new ImageData(['name' => 'hero.jpg', 'size' => 48211]),
				'pdf'       => new FileData(['name' => 'brochure.pdf', 'size' => 0]),
				'empty'     => new ImageData([]),
				'photos'    => new GalleryData([['name' => 'a.jpg', 'size' => 5], ['name' => 'b.jpg']]),
				'downloads' => new DepotData(['files' => [
					['name' => 'readme.txt', 'mime' => 'text/plain', 'size' => 9],
					['name' => 'Price Lists', 'mime' => 'folder', 'files' => [
						['name' => '2026', 'mime' => 'folder', 'files' => [['name' => 'a.pdf', 'mime' => 'application/pdf']]],
					]],
				]]),
				// Cards and deck items are stored as plain arrays.
				'lead' => new CardData(['name' => 'Alice', 'photo' => $image('alice.jpg', 7), 'reel' => ['url' => 'https://youtu.be/x', 'poster' => $image('poster.jpg')]]),
				'team' => new DeckData([
					'bob'   => ['id' => 'bob', 'name' => 'Bob', 'photo' => $image('bob.jpg'), 'cv' => ['name' => 'bob.pdf', 'mime' => 'application/pdf', 'size' => 3]],
					'carol' => ['id' => 'carol', 'photo' => $image('')],
				]),
			]);
		});
		$this->files->method('reconcile')->willReturnCallback(function (string $collection, string $id, string $property, string $path, string $name, ?int $size) use (&$order): string {
			$order[] = trim("{$property}/{$path}", '/') . ': ' . var_export($name, true) . ' ' . var_export($size, true);

			return ['hero.jpg' => 'restored', 'brochure.pdf' => 'missing', 'bob.jpg' => 'restored', 'a.pdf' => 'missing'][$name] ?? ($name === '' ? 'none' : 'present');
		});

		$result = $this->restorer->restoreWithFiles('posts', 'post-1', 'post-1-20240105-120000.json');

		expect($order)->toBe([
			'record saved',
			"cover: 'hero.jpg' 48211",
			// A size of zero says nothing; the name alone decides.
			"pdf: 'brochure.pdf' NULL",
			"empty: '' NULL",
			"photos: 'a.jpg' 5",
			"photos: 'b.jpg' NULL",
			"downloads: 'readme.txt' 9",
			"downloads/Price Lists/2026: 'a.pdf' NULL",
			// A file record is taken whole: the photographer's name inside it is not a file.
			"lead/photo: 'alice.jpg' 7",
			"lead/reel/poster: 'poster.jpg' NULL",
			"team/bob/photo: 'bob.jpg' NULL",
			"team/bob/cv: 'bob.pdf' 3",
			"team/carol/photo: '' NULL",
		])
			// Only what the operator needs to know: what came back, and what could not.
			->and($result['files'])->toBe([
				['property' => 'cover', 'path' => '', 'file' => 'hero.jpg', 'outcome' => 'restored'],
				['property' => 'pdf', 'path' => '', 'file' => 'brochure.pdf', 'outcome' => 'missing'],
				['property' => 'downloads', 'path' => 'Price Lists/2026', 'file' => 'a.pdf', 'outcome' => 'missing'],
				['property' => 'team', 'path' => 'bob/photo', 'file' => 'bob.jpg', 'outcome' => 'restored'],
			])
			->and($result['object']->id)->toBe('post-1');
	}

	public function testReadGivesASnapshotAsTheRecordItHolds(): void
	{
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"someone-else","title":"Old"}');

		$this->assertSame(['id' => 'post-1', 'title' => 'Old'], $this->restorer->read('posts', 'post-1', 'post-1-20240105-120000.json'));
	}

	public function testOneFieldCanBeRestoredOnItsOwn(): void
	{
		// The rest of the record stays as it is now; only this field goes back,
		// and only its files are looked at.
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"post-1","title":"Old title","cover":{"name":"hero.jpg","mime":"image/jpeg","size":5}}');
		$this->objects->method('existsObject')->willReturn(true);
		$this->objects->method('fetchObject')->willReturn(new ObjectData('post-1', [
			'title' => new JsonData('New title'),
			'cover' => new ImageData(['name' => 'banner.jpg']),
			'pdf'   => new FileData(['name' => 'now.pdf']),
		]));

		$this->updater->expects($this->once())->method('updateObject')
			->with('posts', 'post-1', $this->callback(fn (array $data): bool => $data['title'] === 'New title' && $data['cover']['name'] === 'hero.jpg' && $data['pdf']['name'] === 'now.pdf'))
			->willReturn(new ObjectData('post-1', [
				'cover' => new ImageData(['name' => 'hero.jpg', 'size' => 5]),
				'pdf'   => new FileData(['name' => 'now.pdf']),
			]));
		$this->files->expects($this->once())->method('reconcile')->with('posts', 'post-1', 'cover', '', 'hero.jpg', 5)->willReturn('restored');

		$result = $this->restorer->restoreWithFiles('posts', 'post-1', 'post-1-20240105-120000.json', property: 'cover');

		$this->assertSame([['property' => 'cover', 'path' => '', 'file' => 'hero.jpg', 'outcome' => 'restored']], $result['files']);
	}

	public function testOneFieldOfADeletedObjectCannotBeRestored(): void
	{
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"post-1","title":"Old"}');
		$this->objects->method('existsObject')->willReturn(false);
		$this->updater->expects($this->never())->method('updateObject');

		$this->expectException(\UnexpectedValueException::class);
		$this->restorer->restoreWithFiles('posts', 'post-1', 'post-1-20240105-120000.json', property: 'title');
	}

	public function testARestoreCanKeepThePasswordTheRecordHasNow(): void
	{
		// A snapshot of a user holds the old hash. Putting it back would make
		// an old password work again.
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"joe","name":"Old name","password":"$2y$10$oldhash"}');
		$this->objects->method('existsObject')->willReturn(true);
		$current = password_hash('the password they use now', PASSWORD_BCRYPT);
		$this->objects->method('fetchObject')->willReturn(new ObjectData('joe', [
			'name'     => new JsonData('New name'),
			'password' => new PasswordData($current),
		]));

		$this->updater->expects($this->once())->method('updateObject')
			->with('auth', 'joe', ['id' => 'joe', 'name' => 'Old name', 'password' => $current])
			->willReturn(new ObjectData('joe', []));

		$this->restorer->restoreWithFiles('auth', 'joe', 'joe-20240105-120000.json', keepPasswords: true);
	}

	public function testADeletedUserComesBackWithThePasswordTheyHad(): void
	{
		// There is no current password to keep, and without one they could not sign in.
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"joe","password":"$2y$10$oldhash"}');
		$this->objects->method('existsObject')->willReturn(false);

		$this->updater->expects($this->once())->method('updateObject')
			->with('auth', 'joe', ['id' => 'joe', 'password' => '$2y$10$oldhash'])
			->willReturn(new ObjectData('joe', []));

		$this->restorer->restoreWithFiles('auth', 'joe', 'joe-20240105-120000.json', keepPasswords: true);
	}

	public function testARestoreTheSaveRefusesMovesNoFiles(): void
	{
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"post-1"}');
		$this->updater->method('updateObject')->willThrowException(new \DomainException('refused'));
		$this->files->expects($this->never())->method('reconcile');

		$this->expectException(\DomainException::class);
		$this->restorer->restoreWithFiles('posts', 'post-1', 'post-1-20240105-120000.json');
	}

	public function testTheIdArgumentWinsOverWhateverTheSnapshotSays(): void
	{
		// A snapshot copied between objects by hand, or one whose id was
		// edited, must not silently write to a different record.
		$this->store->method('readObjectSnapshot')->willReturn('{"id":"someone-else","title":"x"}');

		$this->updater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'post-1', ['id' => 'post-1', 'title' => 'x'])
			->willReturn(new ObjectData('post-1', []));

		$this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.json');
	}

	public function testMissingSnapshotThrowsBeforeTouchingTheUpdater(): void
	{
		$this->store->method('readObjectSnapshot')->willReturn(null);
		$this->updater->expects($this->never())->method('updateObject');

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage("Snapshot 'nope.json' not found for posts/post-1");

		$this->restorer->restore('posts', 'post-1', 'nope.json');
	}

	public function testCorruptJsonSnapshotThrowsBeforeTouchingTheUpdater(): void
	{
		$this->store->method('readObjectSnapshot')->willReturn('{not json');
		$this->updater->expects($this->never())->method('updateObject');

		$this->expectException(\UnexpectedValueException::class);

		$this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.json');
	}

	public function testMarkdownWithNoReadableSchemaStillRestoresTheFrontmatter(): void
	{
		// Schema lookup failing must not block a restore — the frontmatter
		// fields are still recoverable; only the body has nowhere to go.
		$this->schemaFetcher->method('fetchSchemaForCollection')->willThrowException(new \RuntimeException('no schema'));
		$this->store->method('readObjectSnapshot')->willReturn("---\nid: post-1\ntitle: Old title\n---\n\nBody\n");

		$this->updater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'post-1', $this->callback(fn (array $data): bool => $data['title'] === 'Old title'))
			->willReturn(new ObjectData('post-1', []));

		$this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.md');
	}

	public function testFormatIsDecidedByTheSnapshotExtension(): void
	{
		// The same bytes are frontmatter when the file is .md and garbage when
		// it is .json — extension, not content sniffing, picks the codec path.
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($this->createMock(SchemaData::class));
		$this->store->method('readObjectSnapshot')->willReturn("---\nid: post-1\n---\n");
		$this->updater->method('updateObject')->willReturn(new ObjectData('post-1', []));

		$this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.md');

		$this->expectException(\UnexpectedValueException::class);
		$this->restorer->restore('posts', 'post-1', 'post-1-20240105-120000.json');
	}

	public function testFormatConstantsMatchWhatTheCodecExpects(): void
	{
		// Guards the private formatOf() against a rename of the collection
		// format constants: the restorer must hand the codec the same strings
		// the repository does.
		expect(CollectionData::FORMAT_MARKDOWN)->toBe('markdown');
		expect(CollectionData::FORMAT_JSON)->toBe('json');
	}
}
