<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\DeckFileCleaner;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

final class DeckFileCleanerTest extends TestCase
{
	private DeckFileCleaner $cleaner;
	/** @var SchemaFetcher&MockObject */
	private SchemaFetcher $schemaFetcher;
	/** @var PropertyRepository&MockObject */
	private PropertyRepository $repository;

	protected function setUp(): void
	{
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->repository    = $this->createMock(PropertyRepository::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->cleaner = new DeckFileCleaner(
			$this->schemaFetcher,
			$this->repository,
			$loggerFactory,
		);
	}

	public function testRemovedDeckItemDeletesItsDirectory(): void
	{
		$this->setupDeckSchema();

		$previous = $this->makeObject('item-1', [
			'mydeck' => [
				'a' => ['id' => 'a'],
				'b' => ['id' => 'b'],
			],
		]);
		$current = $this->makeObject('item-1', [
			'mydeck' => [
				'a' => ['id' => 'a'],
			],
		]);

		$this->repository->method('directoryExists')->willReturn(true);
		$this->repository->expects($this->once())
			->method('deleteDirectory')
			->with('posts', 'item-1', 'mydeck', null, 'b');

		$this->cleaner->cleanup('posts', 'item-1', $previous, $current);
	}

	public function testMultipleRemovedItemsAllDeleted(): void
	{
		$this->setupDeckSchema();

		$previous = $this->makeObject('item-1', [
			'mydeck' => [
				'a' => ['id' => 'a'],
				'b' => ['id' => 'b'],
				'c' => ['id' => 'c'],
			],
		]);
		$current = $this->makeObject('item-1', [
			'mydeck' => ['a' => ['id' => 'a']],
		]);

		$this->repository->method('directoryExists')->willReturn(true);
		$this->repository->expects($this->exactly(2))->method('deleteDirectory');

		$this->cleaner->cleanup('posts', 'item-1', $previous, $current);
	}

	public function testNoChangeDoesNothing(): void
	{
		$this->setupDeckSchema();

		$previous = $this->makeObject('item-1', [
			'mydeck' => ['a' => ['id' => 'a', 'image' => ['name' => 'same.jpg']]],
		]);
		$current = $this->makeObject('item-1', [
			'mydeck' => ['a' => ['id' => 'a', 'image' => ['name' => 'same.jpg']]],
		]);

		$this->repository->expects($this->never())->method('deleteDirectory');

		$this->cleaner->cleanup('posts', 'item-1', $previous, $current);
	}

	public function testSurvivingItemWithChangedFileIsLeftAlone(): void
	{
		// Per-child file changes are intentionally out of scope — FileSaver
		// already replaces files at the same path before the form save runs.
		$this->setupDeckSchema();

		$previous = $this->makeObject('item-1', [
			'mydeck' => ['a' => ['id' => 'a', 'image' => ['name' => 'old.jpg']]],
		]);
		$current = $this->makeObject('item-1', [
			'mydeck' => ['a' => ['id' => 'a', 'image' => ['name' => 'new.jpg']]],
		]);

		$this->repository->expects($this->never())->method('deleteDirectory');

		$this->cleaner->cleanup('posts', 'item-1', $previous, $current);
	}

	public function testCardPropertyIsIgnored(): void
	{
		$collectionSchema             = new SchemaData();
		$collectionSchema->properties = [
			'mycard' => [
				'type'      => 'card',
				'field'     => 'card',
				'schemaref' => 'https://www.totalcms.co/schemas/custom/my-card.json',
			],
		];
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($collectionSchema);

		$previous = $this->makeObject('item-1', ['mycard' => ['id' => 'mycard', 'image' => ['name' => 'old.jpg']]]);
		$current  = $this->makeObject('item-1', ['mycard' => ['id' => 'mycard']]);

		$this->repository->expects($this->never())->method('deleteDirectory');

		$this->cleaner->cleanup('posts', 'item-1', $previous, $current);
	}

	public function testFailureToFetchSchemaIsSilent(): void
	{
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willThrowException(new \RuntimeException('no schema'));

		$previous = $this->makeObject('x', []);
		$current  = $this->makeObject('x', []);

		$this->repository->expects($this->never())->method('deleteDirectory');

		$this->cleaner->cleanup('posts', 'x', $previous, $current);
	}

	public function testNonExistentDirectorySkipsDelete(): void
	{
		$this->setupDeckSchema();

		$previous = $this->makeObject('item-1', ['mydeck' => ['gone' => ['id' => 'gone']]]);
		$current  = $this->makeObject('item-1', ['mydeck' => []]);

		$this->repository->method('directoryExists')->willReturn(false);
		$this->repository->expects($this->never())->method('deleteDirectory');

		$this->cleaner->cleanup('posts', 'item-1', $previous, $current);
	}

	private function setupDeckSchema(): void
	{
		$collectionSchema             = new SchemaData();
		$collectionSchema->properties = [
			'mydeck' => [
				'type'      => 'deck',
				'field'     => 'deck',
				'schemaref' => 'https://www.totalcms.co/schemas/custom/my-item.json',
			],
		];
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($collectionSchema);
	}

	/** @param array<string,mixed> $properties */
	private function makeObject(string $id, array $properties): ObjectData
	{
		return new class($id, $properties) extends ObjectData {
			/** @param array<string,mixed> $rawProperties */
			public function __construct(string $id, private readonly array $rawProperties)
			{
				parent::__construct($id, []);
			}

			public function toArray(): array
			{
				return array_merge(['id' => $this->id], $this->rawProperties);
			}
		};
	}
}
