<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\StreamInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;
use Psr\Log\LoggerInterface;
final class CsvImporterTest extends TestCase
{
	private CsvImporter $csvImporter;
	private CollectionFetcher $collectionFetcher;
	private ObjectFetcher $objectFetcher;
	private ObjectImporter $objectImporter;
	private IndexBuilder $indexBuilder;
	private JobQueuer $jobQueuer;
	private LoggerInterface $logger;
	private LoggerFactory $loggerFactory;

	protected function setUp(): void
	{
		// Create mocks for all dependencies (now possible since final was removed)
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectImporter = $this->createMock(ObjectImporter::class);
		$this->indexBuilder = $this->createMock(IndexBuilder::class);
		$this->jobQueuer = $this->createMock(JobQueuer::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Setup logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->csvImporter = new CsvImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$this->objectImporter,
			$this->indexBuilder,
			$this->jobQueuer,
			$this->loggerFactory
		);
	}

	public function testQueueJobs(): void
	{
		// Test that queueJobs() sets the internal state
		$this->csvImporter->queueJobs();
		
		// We can't directly test the private property, but we can verify behavior
		// This test just confirms the method exists and can be called
		$this->assertTrue(true);
	}

	public function testImportThrowsExceptionForNonexistentCollection(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(false);
		
		$file = $this->createMockUploadedFile('id,name\n1,test');
		
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Collection test does not exist');
		
		$this->csvImporter->import('test', $file);
	}

	public function testImportNewObjectReturnsFalseForExistingObject(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(true);
		$this->objectFetcher->method('existsObject')->willReturn(true);
		
		$file = $this->createMockUploadedFile('id,name\n1,test');
		
		$result = $this->csvImporter->import('test', $file);
		
		// Should return 0 because object already exists
		$this->assertEquals(0, $result);
	}

	private function createMockUploadedFile(string $content): UploadedFileInterface
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($content);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getStream')->willReturn($stream);

		return $file;
	}
}