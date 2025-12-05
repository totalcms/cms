<?php

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\TotalCmsOneImporter;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

final class TotalCmsOneImporterTest extends TestCase
{
	private TotalCmsOneImporter $importer;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFactory;
	private \PHPUnit\Framework\MockObject\MockObject $collectionRepository;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;
	private \PHPUnit\Framework\MockObject\MockObject $logger;
	private string $testDataPath;

	protected function setUp(): void
	{
		$this->collectionFetcher     = $this->createMock(CollectionFetcher::class);
		$this->collectionFactory     = $this->createMock(CollectionFactory::class);
		$this->collectionRepository  = $this->createMock(CollectionRepository::class);
		$this->indexReader           = $this->createMock(IndexReader::class);
		$this->jobQueuer             = $this->createMock(JobQueuer::class);
		$this->logger                = $this->createMock(LoggerInterface::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->importer = new TotalCmsOneImporter(
			$this->collectionFetcher,
			$this->collectionFactory,
			$this->collectionRepository,
			$this->indexReader,
			$this->jobQueuer,
			$loggerFactory
		);

		// Create test directory structure
		$this->testDataPath = sys_get_temp_dir() . '/totalcms-import-test-' . uniqid();
		mkdir($this->testDataPath, 0777, true);
	}

	protected function tearDown(): void
	{
		// Clean up test directory
		if (is_dir($this->testDataPath)) {
			$this->removeDirectory($this->testDataPath);
		}
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = array_diff(scandir($dir) ?: [], ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->removeDirectory($path) : unlink($path);
		}
		rmdir($dir);
	}

	private function setupCollectionMocking(array $existingCollections = []): array
	{
		$createdCollections = $existingCollections;

		$this->collectionFetcher->method('collectionExists')
			->willReturnCallback(function ($id) use (&$createdCollections): bool {
				return in_array($id, $createdCollections, true);
			});

		$collection     = $this->createMock(CollectionData::class);
		$collection->id = 'test-id';

		$this->collectionFactory->method('generateCollection')
			->willReturnCallback(function (array $data) use ($collection): \PHPUnit\Framework\MockObject\MockObject {
				$collection->id = $data['id'];

				return $collection;
			});

		$this->collectionRepository->method('saveCollection')
			->willReturnCallback(function ($coll) use (&$createdCollections): void {
				$createdCollections[] = $coll->id;
			});

		return ['collection' => $collection, 'created' => &$createdCollections];
	}

	public function testThrowsExceptionForNonexistentPath(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CMS data path does not exist');

		$this->importer->import('/nonexistent/path');
	}

	public function testImportsAllDataTypes(): void
	{
		// Create empty directory structure
		mkdir($this->testDataPath . '/blog', 0777, true);
		mkdir($this->testDataPath . '/date', 0777, true);
		mkdir($this->testDataPath . '/feed', 0777, true);
		mkdir($this->testDataPath . '/gallery', 0777, true);
		mkdir($this->testDataPath . '/image', 0777, true);
		mkdir($this->testDataPath . '/text', 0777, true);
		mkdir($this->testDataPath . '/video', 0777, true);
		mkdir($this->testDataPath . '/file', 0777, true);
		mkdir($this->testDataPath . '/depot', 0777, true);

		$this->setupCollectionMocking();

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testCreatesBlogCollection(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);

		$this->setupCollectionMocking();

		$this->collectionFactory->expects($this->once())
			->method('generateCollection')
			->with($this->callback(fn ($data): bool => $data['id'] === 'myblog'
					&& $data['schema'] === 'blog-legacy'
					&& $data['name'] === 'Myblog'));

		$this->collectionRepository->expects($this->once())
			->method('saveCollection');

		$this->importer->import($this->testDataPath);
	}

	public function testRenamesBlogCollectionWhenExists(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);

		$index          = $this->createMock(IndexData::class);
		$index->objects = new \Illuminate\Support\Collection(['post1']);

		$this->setupCollectionMocking(['myblog']);

		$this->indexReader->method('fetchIndex')->willReturn($index);

		$this->collectionFactory->expects($this->once())
			->method('generateCollection')
			->with($this->callback(fn ($data): bool => $data['id'] === 'myblog-one'));

		$this->importer->import($this->testDataPath);
	}

	public function testDeletesEmptyBlogCollection(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);

		$index          = $this->createMock(IndexData::class);
		$index->objects = new \Illuminate\Support\Collection([]);

		$this->setupCollectionMocking(['myblog']);

		$this->indexReader->method('fetchIndex')->willReturn($index);

		$this->collectionRepository->expects($this->once())
			->method('deleteCollection')
			->with('myblog');

		$this->importer->import($this->testDataPath);
	}

	public function testSetsBlogUrlFromPosturlFile(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);
		file_put_contents($blogDir . '/myblog.posturl', '/blog/');

		$collectionData = $this->createMock(CollectionData::class);
		$collectionData->method('toArray')->willReturn([
			'id'     => 'myblog',
			'schema' => 'blog-legacy',
			'name'   => 'My Blog',
		]);

		$this->setupCollectionMocking();
		$this->collectionFetcher->method('fetchCollection')->willReturn($collectionData);

		$this->collectionFactory->method('generateCollection')
			->willReturnCallback(function (array $data) use ($collectionData): \PHPUnit\Framework\MockObject\MockObject {
				if (isset($data['url'])) {
					$this->assertEquals('/blog/', $data['url']);
					$this->assertTrue($data['prettyUrl']);
				}

				return $collectionData;
			});

		$this->importer->import($this->testDataPath);
	}

	public function testImportsBlogPost(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);

		$postData = [
			'permalink' => 'my-post',
			'title'     => 'My Post',
			'content'   => 'Post content',
			'timestamp' => 1640000000,
		];

		file_put_contents($blogDir . '/my-post.cms', json_encode($postData));

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('myblog', $this->callback(fn ($data): bool => $data['id'] === 'my-post'
					&& $data['title'] === 'My Post'
					&& isset($data['date'])
					&& !isset($data['permalink'])
					&& !isset($data['timestamp'])));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testImportsBlogPostWithImage(): void
	{
		$blogDir  = $this->testDataPath . '/blog/myblog';
		$imageDir = $blogDir . '/my-post/image';
		mkdir($imageDir, 0777, true);

		file_put_contents($imageDir . '/my-post.jpg', 'fake image');

		$postData = [
			'permalink' => 'my-post',
			'title'     => 'My Post',
			'image'     => 'placeholder',
		];

		file_put_contents($blogDir . '/my-post.cms', json_encode($postData));

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('myblog', $this->callback(fn ($data): bool => isset($data['image']) && str_contains($data['image'], 'my-post.jpg')));

		$this->importer->import($this->testDataPath);
	}

	public function testImportsDates(): void
	{
		$dateDir = $this->testDataPath . '/date';
		mkdir($dateDir, 0777, true);
		file_put_contents($dateDir . '/event-date.cms', '1640000000');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('date', $this->callback(fn ($data): bool => $data['id'] === 'event-date' && isset($data['date'])));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testImportsFeeds(): void
	{
		$feedDir = $this->testDataPath . '/feed/myfeed';
		mkdir($feedDir, 0777, true);
		file_put_contents($feedDir . '/item1.cms', 'Feed content');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('myfeed', $this->callback(fn ($data): bool => $data['id'] === 'item1' && $data['content'] === 'Feed content'));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testImportsGalleries(): void
	{
		$galleryDir = $this->testDataPath . '/gallery/mygallery';
		mkdir($galleryDir, 0777, true);

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('gallery', $this->callback(fn ($data): bool => $data['id'] === 'mygallery' && $data['gallery'] === $galleryDir));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testSkipsBlogGalleries(): void
	{
		$blogGalleryDir = $this->testDataPath . '/gallery/blog';
		mkdir($blogGalleryDir, 0777, true);

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->never())
			->method('queueImport');

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testImportsImages(): void
	{
		$imageDir = $this->testDataPath . '/image';
		mkdir($imageDir, 0777, true);
		file_put_contents($imageDir . '/photo.jpg', 'fake image');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('image', $this->callback(fn ($data): bool => $data['id'] === 'photo' && $data['image'] === $imageDir . '/photo.jpg'));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testSkipsThumbnailImages(): void
	{
		$imageDir = $this->testDataPath . '/image';
		mkdir($imageDir, 0777, true);
		file_put_contents($imageDir . '/photo-th.jpg', 'thumbnail');
		file_put_contents($imageDir . '/photo-sq.jpg', 'square');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->never())
			->method('queueImport');

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testImportsTexts(): void
	{
		$textDir = $this->testDataPath . '/text';
		mkdir($textDir, 0777, true);
		file_put_contents($textDir . '/mytext.cms', 'Text content');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('text', $this->callback(fn ($data): bool => $data['id'] === 'mytext' && $data['text'] === 'Text content'));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testImportsVideos(): void
	{
		$videoDir = $this->testDataPath . '/video';
		mkdir($videoDir, 0777, true);
		file_put_contents($videoDir . '/myvideo.cms', 'https://youtube.com/watch?v=123');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('url', $this->callback(fn ($data): bool => $data['id'] === 'myvideo' && $data['url'] === 'https://youtube.com/watch?v=123'));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testImportsFiles(): void
	{
		$fileDir = $this->testDataPath . '/file';
		mkdir($fileDir, 0777, true);
		file_put_contents($fileDir . '/document.pdf', 'fake pdf');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('file', $this->callback(fn ($data): bool => $data['id'] === 'document' && $data['file'] === $fileDir . '/document.pdf'));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testImportsDepots(): void
	{
		$depotDir = $this->testDataPath . '/depot/mydepot';
		mkdir($depotDir, 0777, true);

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->once())
			->method('queueImport')
			->with('depot', $this->callback(fn ($data): bool => $data['id'] === 'mydepot' && $data['depot'] === $depotDir));

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(1, $count);
	}

	public function testHandlesMissingDirectories(): void
	{
		// Only create the base directory, no subdirectories
		$this->setupCollectionMocking();

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testHandlesBlogPostWithoutId(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);

		$postData = [
			'title'   => 'My Post',
			'content' => 'Post content',
		];

		file_put_contents($blogDir . '/invalid.cms', json_encode($postData));

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->never())
			->method('queueImport');

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testHandlesInvalidBlogPostJson(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);

		file_put_contents($blogDir . '/invalid.cms', 'invalid json');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->never())
			->method('queueImport');

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testHandlesInvalidDateTimestamp(): void
	{
		$dateDir = $this->testDataPath . '/date';
		mkdir($dateDir, 0777, true);
		file_put_contents($dateDir . '/invalid.cms', 'not a timestamp');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->never())
			->method('queueImport');

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(0, $count);
	}

	public function testSetsPrettyUrlBasedOnFormat(): void
	{
		$blogDir = $this->testDataPath . '/blog/myblog';
		mkdir($blogDir, 0777, true);
		file_put_contents($blogDir . '/myblog.posturl', '/index.php?permalink=blog');

		$collectionData = $this->createMock(CollectionData::class);
		$collectionData->method('toArray')->willReturn([
			'id'     => 'myblog',
			'schema' => 'blog-legacy',
			'name'   => 'My Blog',
		]);

		$this->setupCollectionMocking();
		$this->collectionFetcher->method('fetchCollection')->willReturn($collectionData);

		$this->collectionFactory->method('generateCollection')
			->willReturnCallback(function (array $data) use ($collectionData): \PHPUnit\Framework\MockObject\MockObject {
				if (isset($data['url']) && str_contains((string)$data['url'], '?permalink=')) {
					$this->assertFalse($data['prettyUrl']);
				}

				return $collectionData;
			});

		$this->importer->import($this->testDataPath);
	}

	public function testMultipleImportsIncrementCount(): void
	{
		// Create multiple items across different types
		$textDir = $this->testDataPath . '/text';
		mkdir($textDir, 0777, true);
		file_put_contents($textDir . '/text1.cms', 'Text 1');
		file_put_contents($textDir . '/text2.cms', 'Text 2');

		$dateDir = $this->testDataPath . '/date';
		mkdir($dateDir, 0777, true);
		file_put_contents($dateDir . '/date1.cms', '1640000000');

		$this->setupCollectionMocking();

		$this->jobQueuer->expects($this->exactly(3))
			->method('queueImport');

		$count = $this->importer->import($this->testDataPath);

		$this->assertEquals(3, $count);
	}
}
