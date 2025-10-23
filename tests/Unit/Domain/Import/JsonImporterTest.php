<?php

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\JsonImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

final class JsonImporterTest extends TestCase
{
	private JsonImporter $importer;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectImporter;
	private \PHPUnit\Framework\MockObject\MockObject $indexBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $logger;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->objectFetcher     = $this->createMock(ObjectFetcher::class);
		$this->objectImporter    = $this->createMock(ObjectImporter::class);
		$this->indexBuilder      = $this->createMock(IndexBuilder::class);
		$this->jobQueuer         = $this->createMock(JobQueuer::class);
		$this->logger            = $this->createMock(LoggerInterface::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->importer = new JsonImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$this->objectImporter,
			$this->indexBuilder,
			$this->jobQueuer,
			$loggerFactory
		);
	}

	public function testImportsJsonSuccessfully(): void
	{
		$jsonData = json_encode([
			['id' => 'record-1', 'name' => 'Test 1'],
			['id' => 'record-2', 'name' => 'Test 2'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->objectImporter->expects($this->exactly(2))
			->method('importObject');

		$this->indexBuilder->expects($this->once())
			->method('buildIndex')
			->with('products');

		$count = $this->importer->import('products', $file);

		$this->assertEquals(2, $count);
	}

	public function testThrowsExceptionForNonexistentCollection(): void
	{
		$file = $this->createUploadedFile('[]');

		$this->collectionFetcher->expects($this->once())
			->method('collectionExists')
			->with('nonexistent')
			->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Collection nonexistent does not exist');

		$this->importer->import('nonexistent', $file);
	}

	public function testThrowsExceptionForInvalidJson(): void
	{
		$file = $this->createUploadedFile('invalid json');

		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid JSON structure');

		$this->importer->import('products', $file);
	}

	public function testThrowsExceptionForNonArrayJson(): void
	{
		$file = $this->createUploadedFile('"string"');

		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid JSON structure');

		$this->importer->import('products', $file);
	}

	public function testSkipsExistingObjects(): void
	{
		$jsonData = json_encode([
			['id' => 'existing', 'name' => 'Existing'],
			['id' => 'new', 'name' => 'New'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')
			->willReturnMap([
				['products', 'existing', true],
				['products', 'new', false],
			]);

		$this->objectImporter->expects($this->once())
			->method('importObject');

		$count = $this->importer->import('products', $file);

		$this->assertEquals(1, $count);
	}

	public function testUpdateModeUpdatesExistingObjects(): void
	{
		$jsonData = json_encode([
			['id' => 'existing', 'name' => 'Updated'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->objectImporter->expects($this->once())
			->method('updateObject')
			->with('products', ['id' => 'existing', 'name' => 'Updated']);

		$count = $this->importer->import('products', $file, true);

		$this->assertEquals(1, $count);
	}

	public function testUpdateModeSkipsNonexistentObjects(): void
	{
		$jsonData = json_encode([
			['id' => 'nonexistent', 'name' => 'Test'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->objectImporter->expects($this->never())
			->method('updateObject');

		$count = $this->importer->import('products', $file, true);

		$this->assertEquals(0, $count);
	}

	public function testQueuesJobsWhenQueueJobsEnabled(): void
	{
		$jsonData = json_encode([
			['id' => 'record-1', 'name' => 'Test'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->importer->queueJobs();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('products', ['id' => 'record-1', 'name' => 'Test']);

		$this->objectImporter->expects($this->never())
			->method('importObject');

		$this->importer->import('products', $file);
	}

	public function testQueuesUpdateJobsInUpdateMode(): void
	{
		$jsonData = json_encode([
			['id' => 'existing', 'name' => 'Updated'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(true);

		$this->importer->queueJobs();

		$this->jobQueuer->expects($this->once())
			->method('queueUpdate')
			->with('products', ['id' => 'existing', 'name' => 'Updated']);

		$this->importer->import('products', $file, true);
	}

	public function testHandlesImportErrors(): void
	{
		$jsonData = json_encode([
			['id' => 'error', 'name' => 'Test'],
			['id' => 'success', 'name' => 'Test'],
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$objectData = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);

		$this->objectImporter->expects($this->exactly(2))
			->method('importObject')
			->willReturnCallback(function ($collection, array $record) use ($objectData): \PHPUnit\Framework\MockObject\MockObject {
				if ($record['id'] === 'error') {
					throw new \Exception('Import failed');
				}

				return $objectData;
			});

		$count = $this->importer->import('products', $file);

		$this->assertEquals(1, $count);
	}

	public function testRebuildsIndexAfterImport(): void
	{
		$jsonData = json_encode([['id' => 'test', 'name' => 'Test']]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(false);

		$this->indexBuilder->expects($this->once())
			->method('buildIndex')
			->with('products');

		$this->importer->import('products', $file);
	}

	public function testImportNewObjectReturnsFalseForExisting(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		// First, import a record to set the collection
		$jsonData = json_encode([['id' => 'test', 'name' => 'Test']]);
		$file     = $this->createUploadedFile($jsonData);

		$this->objectFetcher->method('existsObject')->willReturn(true);

		$count = $this->importer->import('products', $file);

		$this->assertEquals(0, $count);
	}

	public function testUpdateObjectReturnsFalseForMissingId(): void
	{
		$jsonData = json_encode([
			['name' => 'No ID'], // Missing ID
		]);

		$file = $this->createUploadedFile($jsonData);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$count = $this->importer->import('products', $file, true);

		$this->assertEquals(0, $count);
	}

	private function createUploadedFile(string $content): UploadedFileInterface
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($content);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getStream')->willReturn($stream);

		return $file;
	}
}
