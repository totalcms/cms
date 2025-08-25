<?php

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;
use Webuni\FrontMatter\FrontMatter;

final class AlloyImporter
{
	private readonly LoggerInterface $logger;
	private readonly FrontMatter $frontMatterParser;
	private readonly \Parsedown $markdownParser;
	private int $importCount = 0;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionFactory $collectionFactory,
		private readonly CollectionRepository $collectionRepository,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger            = $loggerFactory->addFileHandler('importer.log')->createLogger('alloy-importer');
		$this->frontMatterParser = new FrontMatter();
		$this->markdownParser    = new \Parsedown();
	}

	/**
	 * @param array{blog: string, image_uploads: string, embeds: string, droplets: string} $folders
	 *
	 * @return array<string,array<mixed>>
	 */
	public function analyze(array $folders): array
	{
		$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$this->logger->info('Starting Alloy data analysis');

		$result = [
			'blogs'    => [],
			'embeds'   => [],
			'droplets' => [],
		];

		// Analyze blog posts
		$blogPath = $documentRoot . '/' . trim($folders['blog'], '/');
		if (is_dir($blogPath)) {
			$blogFiles = glob($blogPath . '/*.md');
			if ($blogFiles) {
				foreach ($blogFiles as $file) {
					$result['blogs'][] = $this->analyzeBlogFile($file);
				}
			}
		}

		// Analyze embeds
		$embedsPath = $documentRoot . '/' . trim($folders['embeds'], '/');
		if (is_dir($embedsPath)) {
			$embedFiles = glob($embedsPath . '/*.md');
			if ($embedFiles) {
				foreach ($embedFiles as $file) {
					$result['embeds'][] = $this->analyzeEmbedFile($file);
				}
			}
		}

		// Analyze droplets
		$dropletsPath = $documentRoot . '/' . trim($folders['droplets'], '/');
		if (is_dir($dropletsPath)) {
			$dropletFiles = glob($dropletsPath . '/*.md');
			if ($dropletFiles) {
				foreach ($dropletFiles as $file) {
					$result['droplets'][] = $this->analyzeDropletFile($file);
				}
			}
		}

		$this->logger->info(sprintf(
			'Alloy analysis completed: %d blogs, %d embeds, %d droplets',
			count($result['blogs']),
			count($result['embeds']),
			count($result['droplets'])
		));

		return $result;
	}

	/**
	 * @param array{blog: string, image_uploads: string, embeds: string, droplets: string} $folders
	 */
	public function import(array $folders): int
	{
		$documentRoot      = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$this->importCount = 0;
		$this->logger->info('Starting Alloy import');

		// Import blogs
		$blogPath         = $documentRoot . '/' . trim($folders['blog'], '/');
		$imageUploadsPath = $documentRoot . '/' . trim($folders['image_uploads'], '/');
		$this->importBlogs($blogPath, $imageUploadsPath);

		// Import embeds
		$embedsPath = $documentRoot . '/' . trim($folders['embeds'], '/');
		$this->importEmbeds($embedsPath);

		// Import droplets
		$dropletsPath = $documentRoot . '/' . trim($folders['droplets'], '/');
		$this->importDroplets($dropletsPath, $imageUploadsPath);

		$this->logger->info(sprintf('Alloy import completed. Total items imported: %d', $this->importCount));

		return $this->importCount;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function analyzeBlogFile(string $file): array
	{
		$filename = basename($file, '.md');

		// Extract date and ID from filename (e.g., 2021-03-01_the-website-is-live.md)
		if (preg_match('/^(\d{4}-\d{2}-\d{2})_(.+)$/', $filename, $matches)) {
			$date = $matches[1];
			$id   = $matches[2];
		} else {
			$date = null;
			$id   = $filename;
		}

		try {
			$content = file_get_contents($file);
			if ($content === false) {
				throw new \RuntimeException('Failed to read file: ' . $file);
			}
			$document    = $this->frontMatterParser->parse($content);
			$frontMatter = $document->getData();

			return [
				'filename'  => $filename,
				'id'        => $id,
				'date'      => $date,
				'title'     => $frontMatter['title'] ?? $id,
				'author'    => $frontMatter['author'] ?? null,
				'category'  => $frontMatter['category'] ?? null,
				'tags'      => $frontMatter['tags'] ?? [],
				'draft'     => $frontMatter['draft'] ?? false,
				'has_image' => !empty($frontMatter['topper']),
			];
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error analyzing blog file %s: %s', $file, $e->getMessage()));

			return ['filename' => $filename, 'error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function analyzeEmbedFile(string $file): array
	{
		$filename = basename($file, '.md');
		$id       = $filename; // Use filename as ID

		try {
			$content = file_get_contents($file);
			if ($content === false) {
				throw new \RuntimeException('Failed to read file: ' . $file);
			}
			$document    = $this->frontMatterParser->parse($content);
			$frontMatter = $document->getData();

			return [
				'filename'       => $filename,
				'id'             => $id,
				'title'          => $frontMatter['title'] ?? $id,
				'content_length' => strlen($document->getContent()),
			];
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error analyzing embed file %s: %s', $file, $e->getMessage()));

			return ['filename' => $filename, 'error' => $e->getMessage()];
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function analyzeDropletFile(string $file): array
	{
		$filename = basename($file, '.md');
		$id       = $filename; // Use filename as ID

		try {
			$content = file_get_contents($file);
			if ($content === false) {
				throw new \RuntimeException('Failed to read file: ' . $file);
			}
			$document    = $this->frontMatterParser->parse($content);
			$frontMatter = $document->getData();

			return [
				'filename' => $filename,
				'id'       => $id,
				'title'    => $frontMatter['title'] ?? $id,
				'type'     => $frontMatter['type'] ?? 'unknown',
				'data'     => $frontMatter['data'] ?? null,
			];
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error analyzing droplet file %s: %s', $file, $e->getMessage()));

			return ['filename' => $filename, 'error' => $e->getMessage()];
		}
	}

	private function importBlogs(string $blogPath, string $imageUploadsPath): void
	{
		if (!is_dir($blogPath)) {
			$this->logger->info('Blog folder not found, skipping blog import');

			return;
		}

		// Create blog collection if it doesn't exist
		$collectionId = 'blog';
		if (!$this->collectionFetcher->collectionExists($collectionId)) {
			$this->createCollection($collectionId, 'blog', 'Blog');
		}

		$blogFiles = glob($blogPath . '/*.md');
		if (!$blogFiles) {
			return;
		}

		foreach ($blogFiles as $file) {
			$this->importBlogPost($file, $collectionId, $imageUploadsPath);
		}
	}

	private function importBlogPost(string $file, string $collectionId, string $imageUploadsPath): void
	{
		try {
			$filename = basename($file, '.md');

			// Extract date and ID from filename
			if (preg_match('/^(\d{4}-\d{2}-\d{2})_(.+)$/', $filename, $matches)) {
				$dateFromFilename = $matches[1];
				$id               = $matches[2];
			} else {
				$dateFromFilename = null;
				$id               = $filename;
			}

			$content = file_get_contents($file);
			if ($content === false) {
				throw new \RuntimeException('Failed to read file: ' . $file);
			}
			$document    = $this->frontMatterParser->parse($content);
			$frontMatter = $document->getData();

			$data = [
				'id'      => $id,
				'title'   => $frontMatter['title'] ?? $id,
				'content' => $this->markdownParser->text($document->getContent()),
				'draft'   => $frontMatter['draft'] ?? false,
			];

			// Add date - prefer filename date if available
			if ($dateFromFilename) {
				$data['date'] = $dateFromFilename . 'T00:00:00+00:00';
			}

			// Add other properties if they exist
			if (isset($frontMatter['author'])) {
				$data['author'] = $frontMatter['author'];
			}

			if (isset($frontMatter['category'])) {
				$data['category'] = $frontMatter['category'];
			}

			if (isset($frontMatter['tags']) && is_array($frontMatter['tags'])) {
				$data['tags'] = $frontMatter['tags'];
			}

			// Handle summary
			if (isset($frontMatter['summary'])) {
				$data['summary'] = '<p>' . trim((string)$frontMatter['summary']) . '</p>';
			}

			// Handle image (topper field)
			if (isset($frontMatter['topper'])) {
				$urlPath = parse_url((string)$frontMatter['topper'], PHP_URL_PATH);
				if ($urlPath !== null && $urlPath !== false) {
					$imageFilename = basename($urlPath);
					$imagePath     = $imageUploadsPath . '/' . $imageFilename;

					if (file_exists($imagePath)) {
						$data['image'] = $imagePath;

						// Add alt text if available
						if (isset($frontMatter['topperalt'])) {
							// Store alt text in a way that can be retrieved later
							// This might need adjustment based on how the system handles alt text
							$data['imageAlt'] = $frontMatter['topperalt'];
						}
					} else {
						$this->logger->warning(sprintf('Image not found for blog post %s: %s', $id, $imagePath));
					}
				}
			}

			$this->jobQueuer->queueImport($collectionId, $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued blog post import: %s/%s', $collectionId, $id));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing blog post %s: %s', $file, $e->getMessage()));
		}
	}

	private function importEmbeds(string $embedsPath): void
	{
		if (!is_dir($embedsPath)) {
			$this->logger->info('Embeds folder not found, skipping embeds import');

			return;
		}

		// Create styledtext collection if it doesn't exist
		$collectionId = 'styledtext';
		if (!$this->collectionFetcher->collectionExists($collectionId)) {
			$this->createCollection($collectionId, 'styledtext', 'StyledText');
		}

		$embedFiles = glob($embedsPath . '/*.md');
		if (!$embedFiles) {
			return;
		}

		foreach ($embedFiles as $file) {
			$this->importEmbedFile($file, $collectionId);
		}
	}

	private function importEmbedFile(string $file, string $collectionId): void
	{
		try {
			$filename = basename($file, '.md');
			$id       = $filename; // Use filename as ID

			$content = file_get_contents($file);
			if ($content === false) {
				throw new \RuntimeException('Failed to read file: ' . $file);
			}
			$document = $this->frontMatterParser->parse($content);

			$data = [
				'id'         => $id,
				'styledtext' => $this->markdownParser->text($document->getContent()),
			];

			$this->jobQueuer->queueImport($collectionId, $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued embed import: %s/%s', $collectionId, $id));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing embed %s: %s', $file, $e->getMessage()));
		}
	}

	private function importDroplets(string $dropletsPath, string $imageUploadsPath): void
	{
		if (!is_dir($dropletsPath)) {
			$this->logger->info('Droplets folder not found, skipping droplets import');

			return;
		}

		$dropletFiles = glob($dropletsPath . '/*.md');
		if (!$dropletFiles) {
			return;
		}

		foreach ($dropletFiles as $file) {
			$this->importDropletFile($file, $imageUploadsPath);
		}
	}

	private function importDropletFile(string $file, string $imageUploadsPath): void
	{
		try {
			$filename = basename($file, '.md');
			$id       = $filename; // Use filename as ID

			$content = file_get_contents($file);
			if ($content === false) {
				throw new \RuntimeException('Failed to read file: ' . $file);
			}
			$document    = $this->frontMatterParser->parse($content);
			$frontMatter = $document->getData();

			$type = $frontMatter['type'] ?? 'text';
			$data = $frontMatter['data'] ?? '';

			if ($type === 'text') {
				// Import as text object
				$collectionId = 'text';
				if (!$this->collectionFetcher->collectionExists($collectionId)) {
					$this->createCollection($collectionId, 'text', 'Texts');
				}

				$objectData = [
					'id'   => $id,
					'text' => $data,
				];

				$this->jobQueuer->queueImport($collectionId, $objectData);
				$this->importCount++;
				$this->logger->info(sprintf('Queued text droplet import: %s/%s', $collectionId, $id));
			} elseif ($type === 'image') {
				// Import as image object
				$collectionId = 'image';
				if (!$this->collectionFetcher->collectionExists($collectionId)) {
					$this->createCollection($collectionId, 'image', 'Images');
				}

				// Extract filename from URL and construct path
				$urlPath = parse_url($data, PHP_URL_PATH);
				if ($urlPath !== null && $urlPath !== false) {
					$imageFilename = basename($urlPath);
					$imagePath     = $imageUploadsPath . '/' . $imageFilename;

					if (file_exists($imagePath)) {
						$objectData = [
							'id'    => $id,
							'image' => $imagePath,
						];

						$this->jobQueuer->queueImport($collectionId, $objectData);
						$this->importCount++;
						$this->logger->info(sprintf('Queued image droplet import: %s/%s', $collectionId, $id));
					} else {
						$this->logger->warning(sprintf('Image not found for droplet %s: %s', $id, $imagePath));
					}
				}
			} else {
				$this->logger->warning(sprintf('Unknown droplet type "%s" in file %s', $type, $file));
			}
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing droplet %s: %s', $file, $e->getMessage()));
		}
	}

	private function createCollection(string $id, string $schema, string $name): void
	{
		try {
			$collectionData = [
				'id'     => $id,
				'schema' => $schema,
				'name'   => $name,
			];

			$collection = $this->collectionFactory->generateCollection($collectionData);
			$this->collectionRepository->saveCollection($collection);

			// Verify collection was created successfully
			if (!$this->collectionFetcher->collectionExists($id)) {
				throw new \RuntimeException(sprintf('Collection %s was not created successfully', $id));
			}

			$this->logger->info(sprintf('Created collection: %s with schema: %s', $id, $schema));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error creating collection %s: %s', $id, $e->getMessage()));
			throw $e;
		}
	}
}
