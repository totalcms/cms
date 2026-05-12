<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Import\DeckJsonImporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

final class DeckJsonImporterTest extends TestCase
{
	private DeckJsonImporter $importer;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;

	protected function setUp(): void
	{
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);

		$logger        = $this->createMock(LoggerInterface::class);
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($logger);

		$this->importer = new DeckJsonImporter(
			$this->objectFetcher,
			$this->objectUpdater,
			$this->schemaFetcher,
			$loggerFactory,
		);
	}

	public function testImportDictionaryFormat(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);
		$object->method('toArray')->willReturn(['id' => 'obj-1', 'items' => []]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->once())->method('updateObject');

		$json = json_encode([
			'item_a' => ['name' => 'Alpha'],
			'item_b' => ['name' => 'Beta'],
		]);

		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($json));

		expect($count)->toBe(2);
	}

	public function testImportArrayFormat(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);
		$object->method('toArray')->willReturn(['id' => 'obj-1', 'items' => []]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->once())->method('updateObject');

		$json = json_encode([
			['id' => 'item_a', 'name' => 'Alpha'],
			['id' => 'item_b', 'name' => 'Beta'],
		]);

		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($json));

		expect($count)->toBe(2);
	}

	public function testSkipsExistingItemsWithoutUpdateFlag(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = ['item_a' => ['id' => 'item_a', 'name' => 'Existing']];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);
		$object->method('toArray')->willReturn(['id' => 'obj-1', 'items' => $deckData->deck]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->once())->method('updateObject');

		$json = json_encode([
			'item_a' => ['name' => 'Updated'],
			'item_b' => ['name' => 'New'],
		]);

		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($json));

		// Only item_b is new, item_a is skipped
		expect($count)->toBe(1);
	}

	public function testUpdatesExistingItemsWithUpdateFlag(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = ['item_a' => ['id' => 'item_a', 'name' => 'Old']];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);
		$object->method('toArray')->willReturn(['id' => 'obj-1', 'items' => $deckData->deck]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->once())->method('updateObject');

		$json = json_encode([
			'item_a' => ['name' => 'Updated'],
		]);

		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($json), true);

		expect($count)->toBe(1);
	}

	public function testReturnsZeroForEmptyData(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');

		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile('[]'));

		expect($count)->toBe(0);
	}

	public function testThrowsForNonDeckProperty(): void
	{
		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['title' => 'not a deck']);

		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("Property 'title' is not a deck property");

		$this->importer->import('posts', 'obj-1', 'title', $this->createFile('{}'));
	}

	public function testThrowsForInvalidJson(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid JSON');

		$this->importer->import('posts', 'obj-1', 'items', $this->createFile('not json'));
	}

	private function createFile(string $content): UploadedFileInterface
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($content);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getStream')->willReturn($stream);

		return $file;
	}
}
