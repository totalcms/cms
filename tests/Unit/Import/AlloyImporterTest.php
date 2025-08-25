<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\AlloyImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

/**
 * AlloyImporter tests with proper mocking now that final keywords have been removed.
 */
class AlloyImporterTest extends TestCase
{
	private AlloyImporter $alloyImporter;
	private CollectionFetcher $collectionFetcher;
	private CollectionFactory $collectionFactory;
	private CollectionRepository $collectionRepository;
	private JobQueuer $jobQueuer;
	private LoggerFactory $loggerFactory;
	private LoggerInterface $logger;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/alloy_test_' . uniqid();
		mkdir($this->tempDir);

		// Create mock dependencies (now possible since final was removed)
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionFactory = $this->createMock(CollectionFactory::class);
		$this->collectionRepository = $this->createMock(CollectionRepository::class);
		$this->jobQueuer = $this->createMock(JobQueuer::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->loggerFactory = $this->createMock(LoggerFactory::class);

		// Setup logger factory chain
		$this->loggerFactory->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->method('createLogger')->willReturn($this->logger);

		// Configure collection mocks to support creation verification
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$this->alloyImporter = new AlloyImporter(
			$this->collectionFetcher,
			$this->collectionFactory,
			$this->collectionRepository,
			$this->jobQueuer,
			$this->loggerFactory
		);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->tempDir)) {
			$this->removeDirectory($this->tempDir);
		}
	}

	private function removeDirectory(string $dir): void
	{
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$filePath = $dir . '/' . $file;
			is_dir($filePath) ? $this->removeDirectory($filePath) : unlink($filePath);
		}
		rmdir($dir);
	}

	public function testAnalyzeWithNonexistentDirectories(): void
	{
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		$folders = [
			'blog' => 'nonexistent-blog',
			'image_uploads' => 'nonexistent-uploads',
			'embeds' => 'nonexistent-embeds',
			'droplets' => 'nonexistent-droplets'
		];

		$result = $this->alloyImporter->analyze($folders);

		$this->assertArrayHasKey('blogs', $result);
		$this->assertArrayHasKey('embeds', $result);
		$this->assertArrayHasKey('droplets', $result);
		$this->assertEmpty($result['blogs']);
		$this->assertEmpty($result['embeds']);
		$this->assertEmpty($result['droplets']);
	}

	public function testImportWithEmptyDirectories(): void
	{
		$_SERVER['DOCUMENT_ROOT'] = $this->tempDir;

		// Create empty directories
		mkdir($this->tempDir . '/blog');
		mkdir($this->tempDir . '/embeds');
		mkdir($this->tempDir . '/droplets');

		$folders = [
			'blog' => 'blog',
			'image_uploads' => 'uploads',
			'embeds' => 'embeds',
			'droplets' => 'droplets'
		];

		$importCount = $this->alloyImporter->import($folders);
		$this->assertEquals(0, $importCount);
	}
}