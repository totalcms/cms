<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Import\DeckCsvImporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

final class DeckCsvImporterTest extends TestCase
{
	private DeckCsvImporter $importer;
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

		$this->importer = new DeckCsvImporter(
			$this->objectFetcher,
			$this->objectUpdater,
			$this->schemaFetcher,
			new EventDispatcher(new NullLogger()),
			$loggerFactory,
		);
	}

	public function testImportCsvWithIdColumn(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);
		$object->method('toArray')->willReturn(['id' => 'obj-1', 'items' => []]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->once())->method('updateObject');

		$csv   = "id,name,value\nitem_a,Alpha,100\nitem_b,Beta,200";
		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($csv));

		expect($count)->toBe(2);
	}

	public function testImportCsvWithoutIdColumnGeneratesIds(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);
		$object->method('toArray')->willReturn(['id' => 'obj-1', 'items' => []]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->once())->method('updateObject');

		$csv   = "name,value\nAlpha,100\nBeta,200";
		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($csv));

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

		$csv   = "id,name\nitem_a,Updated\nitem_b,New";
		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($csv));

		// Only item_b is new
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

		$csv   = "id,name\nitem_a,Updated";
		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($csv), true);

		expect($count)->toBe(1);
	}

	public function testReturnsZeroForEmptyCsv(): void
	{
		$deckData       = $this->createMock(DeckData::class);
		$deckData->deck = [];

		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['items' => $deckData]);

		$this->objectFetcher->method('fetchObject')->willReturn($object);
		$this->objectUpdater->expects($this->never())->method('updateObject');

		$csv   = 'id,name';
		$count = $this->importer->import('posts', 'obj-1', 'items', $this->createFile($csv));

		expect($count)->toBe(0);
	}

	public function testThrowsForNonDeckProperty(): void
	{
		$object             = $this->createMock(ObjectData::class);
		$object->properties = new Collection(['title' => 'not a deck']);

		$this->objectFetcher->method('fetchObject')->willReturn($object);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("Property 'title' is not a deck property");

		$this->importer->import('posts', 'obj-1', 'title', $this->createFile('id,name'));
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
