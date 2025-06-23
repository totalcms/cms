<?php

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

final class TotalCmsOneImporter
{
	private LoggerInterface $logger;
	private string $cmsDataPath;
	private int $importCount = 0;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private CollectionFactory $collectionFactory,
		private CollectionRepository $collectionRepository,
		private JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('totalcms-one-importer');
	}

	public function import(string $cmsDataPath): int
	{
		if (!is_dir($cmsDataPath)) {
			$error = sprintf('CMS data path does not exist: %s', $cmsDataPath);
			$this->logger->error($error);
			throw new \InvalidArgumentException($error);
		}

		$this->cmsDataPath = rtrim($cmsDataPath, '/');
		$this->importCount = 0;

		$this->logger->info(sprintf('Starting Total CMS 1 import from: %s', $this->cmsDataPath));

		// Import each data type in order
		$this->importBlogs();
		$this->importDates();
		$this->importDepots();
		$this->importFeeds();
		$this->importFiles();
		$this->importGalleries();
		$this->importImages();
		$this->importTexts();
		$this->importVideos();

		$this->logger->info(sprintf('Total CMS 1 import completed. Total items imported: %d', $this->importCount));

		return $this->importCount;
	}

	private function importBlogs(): void
	{
		$blogPath = $this->cmsDataPath . '/blog';
		if (!is_dir($blogPath)) {
			$this->logger->info('No blog folder found, skipping blog import');
			return;
		}

		$blogDirs = glob($blogPath . '/*', GLOB_ONLYDIR);
		if (!$blogDirs) {
			return;
		}

		foreach ($blogDirs as $blogDir) {
			$blogId = basename($blogDir);
			$this->logger->info(sprintf('Importing blog: %s', $blogId));

			// Create collection if it doesn't exist
			if (!$this->collectionFetcher->collectionExists($blogId)) {
				$this->createCollection($blogId, 'blog-legacy', 'Blog: ' . $blogId);
			}

			// Check for .posturl file to set collection URL
			$posturlFile = $blogDir . '/' . $blogId . '.posturl';
			if (file_exists($posturlFile)) {
				$url = trim((string)file_get_contents($posturlFile));
				if ($url) {
					$collection = $this->collectionFetcher->fetchCollection($blogId);
					if ($collection !== null) {
						$collectionData = $collection->toArray();
						$collectionData['url'] = $url;
						$collectionData['prettyUrl'] = !str_contains($url, '?permalink=');
						
						$updatedCollection = $this->collectionFactory->generateCollection($collectionData);
						$this->collectionRepository->saveCollection($updatedCollection);
					}
				}
			}

			// Import blog posts
			$blogPosts = glob($blogDir . '/*.cms');
			if (!$blogPosts) {
				continue;
			}

			foreach ($blogPosts as $postFile) {
				$this->importBlogPost($blogId, $postFile);
			}
		}
	}

	private function importBlogPost(string $collectionId, string $postFile): void
	{
		try {
			$postData = json_decode((string)file_get_contents($postFile), true);
			if (!is_array($postData)) {
				$this->logger->error(sprintf('Invalid blog post data in file: %s', $postFile));
				return;
			}

			// Transform the data
			if (isset($postData['permalink'])) {
				$postData['id'] = $postData['permalink'];
				unset($postData['permalink']);
			}

			// Convert timestamp to ISO8601
			if (isset($postData['timestamp'])) {
				$postData['date'] = date('c', (int)$postData['timestamp']);
				unset($postData['timestamp']);
			}

			// Clear image and gallery fields as they will be reprocessed
			if (isset($postData['image'])) {
				$blogDir = dirname($postFile);
				$imageDir = $blogDir . '/' . $postData['id'] . '/image';
				if (is_dir($imageDir)) {
					$postData['image'] = $imageDir;
				} else {
					unset($postData['image']);
				}
			}

			if (isset($postData['gallery'])) {
				$galleryDir = $this->cmsDataPath . '/gallery/blog/' . $collectionId . '/' . $postData['id'];
				if (is_dir($galleryDir)) {
					$postData['gallery'] = $galleryDir;
				} else {
					unset($postData['gallery']);
				}
			}

			// Queue the import job
			$this->jobQueuer->queueImport($collectionId, $postData);
			$this->importCount++;
			$this->logger->info(sprintf('Queued blog post import: %s/%s', $collectionId, $postData['id']));

		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing blog post %s: %s', $postFile, $e->getMessage()));
		}
	}

	private function importDates(): void
	{
		$datePath = $this->cmsDataPath . '/date';
		if (!is_dir($datePath)) {
			$this->logger->info('No date folder found, skipping date import');
			return;
		}

		// Create date collection if it doesn't exist
		if (!$this->collectionFetcher->collectionExists('date')) {
			$this->createCollection('date', 'date', 'Dates');
		}

		$dateFiles = glob($datePath . '/*.cms');
		if (!$dateFiles) {
			return;
		}

		foreach ($dateFiles as $dateFile) {
			try {
				$id = basename($dateFile, '.cms');
				$timestamp = trim((string)file_get_contents($dateFile));
				
				if (!is_numeric($timestamp)) {
					$this->logger->error(sprintf('Invalid timestamp in date file: %s', $dateFile));
					continue;
				}

				$data = [
					'id' => $id,
					'date' => date('c', (int)$timestamp)
				];

				$this->jobQueuer->queueImport('date', $data);
				$this->importCount++;
				$this->logger->info(sprintf('Queued date import: %s', $id));

			} catch (\Exception $e) {
				$this->logger->error(sprintf('Error importing date %s: %s', $dateFile, $e->getMessage()));
			}
		}
	}

	private function importDepots(): void
	{
		$depotPath = $this->cmsDataPath . '/depot';
		if (!is_dir($depotPath)) {
			$this->logger->info('No depot folder found, skipping depot import');
			return;
		}

		// Create depot collection if it doesn't exist
		if (!$this->collectionFetcher->collectionExists('depot')) {
			$this->createCollection('depot', 'depot', 'Depots');
		}

		$depotDirs = glob($depotPath . '/*', GLOB_ONLYDIR);
		if (!$depotDirs) {
			return;
		}

		foreach ($depotDirs as $depotDir) {
			$id = basename($depotDir);
			
			$data = [
				'id' => $id,
				'depot' => $depotDir
			];

			$this->jobQueuer->queueImport('depot', $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued depot import: %s', $id));
		}
	}

	private function importFeeds(): void
	{
		$feedPath = $this->cmsDataPath . '/feed';
		if (!is_dir($feedPath)) {
			$this->logger->info('No feed folder found, skipping feed import');
			return;
		}

		$feedDirs = glob($feedPath . '/*', GLOB_ONLYDIR);
		if (!$feedDirs) {
			return;
		}

		foreach ($feedDirs as $feedDir) {
			$feedId = basename($feedDir);
			$this->logger->info(sprintf('Importing feed: %s', $feedId));

			// Create collection if it doesn't exist
			if (!$this->collectionFetcher->collectionExists($feedId)) {
				$this->createCollection($feedId, 'feed', 'Feed: ' . $feedId);
			}

			// Import feed items
			$feedFiles = glob($feedDir . '/*.cms');
			if (!$feedFiles) {
				continue;
			}

			foreach ($feedFiles as $feedFile) {
				try {
					$id = basename($feedFile, '.cms');
					$content = file_get_contents($feedFile);
					
					$data = [
						'id' => $id,
						'title' => $id,
						'content' => $content
					];

					// Check for image
					$imageFile = $this->cmsDataPath . '/gallery/feed-' . $id . '/feed-' . $id . '.jpg';
					if (file_exists($imageFile)) {
						$data['image'] = $imageFile;
					}

					$this->jobQueuer->queueImport($feedId, $data);
					$this->importCount++;
					$this->logger->info(sprintf('Queued feed item import: %s/%s', $feedId, $id));

				} catch (\Exception $e) {
					$this->logger->error(sprintf('Error importing feed item %s: %s', $feedFile, $e->getMessage()));
				}
			}
		}
	}

	private function importFiles(): void
	{
		$filePath = $this->cmsDataPath . '/file';
		if (!is_dir($filePath)) {
			$this->logger->info('No file folder found, skipping file import');
			return;
		}

		// Create file collection if it doesn't exist
		if (!$this->collectionFetcher->collectionExists('file')) {
			$this->createCollection('file', 'file', 'Files');
		}

		$files = glob($filePath . '/*');
		if (!$files) {
			return;
		}

		foreach ($files as $file) {
			if (is_file($file)) {
				$id = basename($file);
				
				$data = [
					'id' => $id,
					'file' => $file
				];

				$this->jobQueuer->queueImport('file', $data);
				$this->importCount++;
				$this->logger->info(sprintf('Queued file import: %s', $id));
			}
		}
	}

	private function importGalleries(): void
	{
		$galleryPath = $this->cmsDataPath . '/gallery';
		if (!is_dir($galleryPath)) {
			$this->logger->info('No gallery folder found, skipping gallery import');
			return;
		}

		// Create gallery collection if it doesn't exist
		if (!$this->collectionFetcher->collectionExists('gallery')) {
			$this->createCollection('gallery', 'gallery', 'Galleries');
		}

		$galleryDirs = glob($galleryPath . '/*', GLOB_ONLYDIR);
		if (!$galleryDirs) {
			return;
		}

		foreach ($galleryDirs as $galleryDir) {
			$dirName = basename($galleryDir);
			
			// Skip blog and feed galleries
			if ($dirName === 'blog' || str_starts_with($dirName, 'feed-')) {
				continue;
			}

			$data = [
				'id' => $dirName,
				'gallery' => $galleryDir
			];

			$this->jobQueuer->queueImport('gallery', $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued gallery import: %s', $dirName));
		}
	}

	private function importImages(): void
	{
		$imagePath = $this->cmsDataPath . '/image';
		if (!is_dir($imagePath)) {
			$this->logger->info('No image folder found, skipping image import');
			return;
		}

		// Create image collection if it doesn't exist
		if (!$this->collectionFetcher->collectionExists('image')) {
			$this->createCollection('image', 'image', 'Images');
		}

		$images = glob($imagePath . '/*');
		if (!$images) {
			return;
		}

		foreach ($images as $image) {
			if (is_file($image) && !str_ends_with($image, '.cms')) {
				$filename = pathinfo($image, PATHINFO_FILENAME);
				
				// Skip thumbnail and square versions
				if (str_ends_with($filename, '-th') || str_ends_with($filename, '-sq')) {
					continue;
				}

				$data = [
					'id' => $filename,
					'image' => $image
				];

				$this->jobQueuer->queueImport('image', $data);
				$this->importCount++;
				$this->logger->info(sprintf('Queued image import: %s', $filename));
			}
		}
	}

	private function importTexts(): void
	{
		$textPath = $this->cmsDataPath . '/text';
		if (!is_dir($textPath)) {
			$this->logger->info('No text folder found, skipping text import');
			return;
		}

		// Create text collection if it doesn't exist
		if (!$this->collectionFetcher->collectionExists('text')) {
			$this->createCollection('text', 'text', 'Texts');
		}

		$textFiles = glob($textPath . '/*.cms');
		if (!$textFiles) {
			return;
		}

		foreach ($textFiles as $textFile) {
			try {
				$id = basename($textFile, '.cms');
				$content = file_get_contents($textFile);
				
				$data = [
					'id' => $id,
					'text' => $content
				];

				$this->jobQueuer->queueImport('text', $data);
				$this->importCount++;
				$this->logger->info(sprintf('Queued text import: %s', $id));

			} catch (\Exception $e) {
				$this->logger->error(sprintf('Error importing text %s: %s', $textFile, $e->getMessage()));
			}
		}
	}

	private function importVideos(): void
	{
		$videoPath = $this->cmsDataPath . '/video';
		if (!is_dir($videoPath)) {
			$this->logger->info('No video folder found, skipping video import');
			return;
		}

		// Create url collection if it doesn't exist (videos go into url collection)
		if (!$this->collectionFetcher->collectionExists('url')) {
			$this->createCollection('url', 'url', 'URLs');
		}

		$videoFiles = glob($videoPath . '/*.cms');
		if (!$videoFiles) {
			return;
		}

		foreach ($videoFiles as $videoFile) {
			try {
				$id = basename($videoFile, '.cms');
				$url = trim((string)file_get_contents($videoFile));
				
				$data = [
					'id' => $id,
					'url' => $url
				];

				$this->jobQueuer->queueImport('url', $data);
				$this->importCount++;
				$this->logger->info(sprintf('Queued video/url import: %s', $id));

			} catch (\Exception $e) {
				$this->logger->error(sprintf('Error importing video %s: %s', $videoFile, $e->getMessage()));
			}
		}
	}

	private function createCollection(string $id, string $schema, string $name): void
	{
		try {
			$collectionData = [
				'id' => $id,
				'schema' => $schema,
				'name' => $name
			];

			$collection = $this->collectionFactory->generateCollection($collectionData);
			$this->collectionRepository->saveCollection($collection);
			
			$this->logger->info(sprintf('Created collection: %s with schema: %s', $id, $schema));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error creating collection %s: %s', $id, $e->getMessage()));
			throw $e;
		}
	}
}