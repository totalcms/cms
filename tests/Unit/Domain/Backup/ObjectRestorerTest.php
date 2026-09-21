<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Backup;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Backup\Service\ObjectRestorer;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFileCodec;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
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

	protected function setUp(): void
	{
		$this->store         = $this->createMock(BackupStore::class);
		$this->updater       = $this->createMock(ObjectUpdater::class);
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);

		$this->restorer = new ObjectRestorer(
			$this->store,
			$this->updater,
			new ObjectFileCodec(),
			$this->schemaFetcher,
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
