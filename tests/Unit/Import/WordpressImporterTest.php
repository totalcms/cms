<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\WordpressImporter;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

class WordpressImporterTest extends TestCase
{
	private WordpressImporter $importer;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private string $sampleXml;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->jobQueuer         = $this->createMock(JobQueuer::class);
		$logger                  = $this->createMock(LoggerInterface::class);
		$loggerFactory           = $this->createMock(LoggerFactory::class);

		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($logger);

		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$jobDataStub = new JobData();
		$jobDataStub->id = 'test-job-id';
		$this->jobQueuer->method('queueImport')->willReturn($jobDataStub);

		$this->importer = new WordpressImporter(
			$this->collectionFetcher,
			$this->jobQueuer,
			$loggerFactory,
		);

		$xmlPath = __DIR__ . '/../../test-data/sample-wordpress-export.xml';
		$content = file_get_contents($xmlPath);
		$this->assertIsString($content);
		$this->sampleXml = $content;
	}

	public function testAnalyzeCountsPosts(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		// 4 posts, 1 page (skipped), 1 attachment (skipped)
		$this->assertSame(4, $result['posts']);
	}

	public function testAnalyzeSkipsPages(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		$titles = array_column($result['sample'], 'title');
		$this->assertNotContains('About Us', $titles);
	}

	public function testAnalyzeDateRange(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		$this->assertSame('2025-01-01 00:00:00', $result['dateRange']['earliest']);
		$this->assertSame('2025-03-03 14:15:00', $result['dateRange']['latest']);
	}

	public function testAnalyzeExtractsCategories(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		$this->assertContains('Technology', $result['categories']);
		$this->assertContains('Tutorials', $result['categories']);
	}

	public function testAnalyzeExtractsTags(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		$this->assertContains('PHP', $result['tags']);
		$this->assertContains('Web Development', $result['tags']);
	}

	public function testAnalyzeExtractsAuthors(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		$this->assertContains('admin', $result['authors']);
		$this->assertContains('janewriter', $result['authors']);
	}

	public function testAnalyzeSampleLimitedToTen(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		// Our sample has only 4 posts, so sample = all 4
		$this->assertCount(4, $result['sample']);
	}

	public function testAnalyzeSampleIncludesStatus(): void
	{
		$result = $this->importer->analyze($this->sampleXml);

		$drafts = array_filter($result['sample'], fn ($p) => $p['status'] === 'draft');
		$this->assertCount(1, $drafts);
	}

	public function testImportQueuesCorrectCount(): void
	{
		$count = $this->importer->import($this->sampleXml, 'blog', ['draft' => true]);
		$this->assertSame(4, $count);
	}

	public function testImportUsesPostNameAsSlug(): void
	{
		$queuedData = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queuedData): JobData {
				$queuedData[] = $data;
				$stub = new JobData();
				$stub->id = 'job-' . count($queuedData);
				return $stub;
			});

		$this->importer->import($this->sampleXml, 'blog');

		$slugs = array_column($queuedData, 'id');
		$this->assertContains('getting-started-with-php-82', $slugs);
		$this->assertContains('hello-world', $slugs);
		$this->assertContains('understanding-dependency-injection', $slugs);
		$this->assertContains('building-rest-apis-with-slim-4', $slugs);
	}

	public function testImportMapsContentAndExcerpt(): void
	{
		$queuedData = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queuedData): JobData {
				$queuedData[] = $data;
				$stub = new JobData();
				$stub->id = 'job-' . count($queuedData);
				return $stub;
			});

		$this->importer->import($this->sampleXml, 'blog');

		// Find the PHP 8.2 post
		$phpPost = null;
		foreach ($queuedData as $data) {
			if ($data['id'] === 'getting-started-with-php-82') {
				$phpPost = $data;
			}
		}

		$this->assertNotNull($phpPost);
		$this->assertStringContainsString('Readonly Classes', $phpPost['content']);
		$this->assertStringContainsString('readonly classes', $phpPost['summary']);
	}

	public function testImportMapsCategoriesAndTags(): void
	{
		$queuedData = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queuedData): JobData {
				$queuedData[] = $data;
				$stub = new JobData();
				$stub->id = 'job-' . count($queuedData);
				return $stub;
			});

		$this->importer->import($this->sampleXml, 'blog');

		$phpPost = null;
		foreach ($queuedData as $data) {
			if ($data['id'] === 'getting-started-with-php-82') {
				$phpPost = $data;
			}
		}

		$this->assertNotNull($phpPost);
		$this->assertStringContainsString('Technology', $phpPost['category']);
		$this->assertStringContainsString('Tutorials', $phpPost['category']);
		$this->assertStringContainsString('PHP', $phpPost['tags']);
		$this->assertStringContainsString('Web Development', $phpPost['tags']);
	}

	public function testImportDraftFlagHonored(): void
	{
		$queuedData = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queuedData): JobData {
				$queuedData[] = $data;
				$stub = new JobData();
				$stub->id = 'job-' . count($queuedData);
				return $stub;
			});

		// Import with draft=false — published posts should be non-draft
		$this->importer->import($this->sampleXml, 'blog', ['draft' => false]);

		foreach ($queuedData as $data) {
			if ($data['id'] === 'getting-started-with-php-82') {
				// Published post → draft=false
				$this->assertFalse($data['draft'], 'Published post should not be draft when draft=false');
			}
			if ($data['id'] === 'understanding-dependency-injection') {
				// WordPress draft → always draft=true
				$this->assertTrue($data['draft'], 'WordPress draft should remain draft');
			}
		}
	}

	public function testImportConvertsDate(): void
	{
		$queuedData = [];
		$this->jobQueuer->method('queueImport')
			->willReturnCallback(function (string $collection, array $data) use (&$queuedData): JobData {
				$queuedData[] = $data;
				$stub = new JobData();
				$stub->id = 'job-' . count($queuedData);
				return $stub;
			});

		$this->importer->import($this->sampleXml, 'blog');

		$phpPost = null;
		foreach ($queuedData as $data) {
			if ($data['id'] === 'getting-started-with-php-82') {
				$phpPost = $data;
			}
		}

		$this->assertNotNull($phpPost);
		// Should be ISO 8601 format
		$this->assertStringContainsString('2025-01-15', $phpPost['date']);
		$this->assertStringContainsString('T', $phpPost['date']);
	}

	public function testImportThrowsForMissingCollection(): void
	{
		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		$collectionFetcher->method('collectionExists')->willReturn(false);

		$logger        = $this->createMock(LoggerInterface::class);
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($logger);

		$importer = new WordpressImporter($collectionFetcher, $this->jobQueuer, $loggerFactory);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('does not exist');
		$importer->import($this->sampleXml, 'nonexistent');
	}

	public function testAnalyzeThrowsOnInvalidXml(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to parse WXR XML');
		$this->importer->analyze('this is not xml');
	}
}
