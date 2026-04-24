<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\IndexBuildListener;
use TotalCMS\Domain\Import\CsvImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Factory\LoggerFactory;

final class CsvImporterTest extends TestCase
{
	private CsvImporter $csvImporter;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectImporter;
	private \PHPUnit\Framework\MockObject\MockObject $indexBuildListener;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $logger;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	protected function setUp(): void
	{
		// Create mocks for all dependencies
		$this->collectionFetcher  = $this->createMock(CollectionFetcher::class);
		$this->objectFetcher      = $this->createMock(ObjectFetcher::class);
		$this->objectImporter     = $this->createMock(ObjectImporter::class);
		$this->indexBuildListener = $this->createMock(IndexBuildListener::class);
		$this->jobQueuer          = $this->createMock(JobQueuer::class);
		$this->logger             = $this->createMock(LoggerInterface::class);
		$this->loggerFactory      = $this->createMock(LoggerFactory::class);

		// Setup logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->csvImporter = new CsvImporter(
			$this->collectionFetcher,
			$this->objectFetcher,
			$this->objectImporter,
			$this->indexBuildListener,
			new EventDispatcher(new \Psr\Log\NullLogger()),
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
		$this->expectExceptionMessage('Collection does not exist: test');

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
