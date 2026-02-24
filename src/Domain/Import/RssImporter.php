<?php

namespace TotalCMS\Domain\Import;

use GuzzleHttp\Client;
use Laminas\Feed\Reader\Entry\AbstractEntry;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\Reader;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

class RssImporter
{
	private readonly LoggerInterface $logger;
	private int $importCount = 0;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('rss-importer');
	}

	/**
	 * Analyze an RSS/Atom feed and return a preview of its contents.
	 *
	 * @return array{feed: array<string,mixed>, entries: array<int,array<string,mixed>>}
	 */
	public function analyze(string $feedUrl): array
	{
		$this->logger->info(sprintf('Starting RSS feed analysis: %s', $feedUrl));

		$feed = $this->fetchFeed($feedUrl);

		$feedData = [
			'title'       => $feed->getTitle() ?? '',
			'description' => $feed->getDescription() ?? '',
			'link'        => $feed->getLink() ?? '',
			'count'       => $feed->count(),
		];

		$entries = [];
		foreach ($feed as $entry) {
			/** @var EntryInterface $entry */
			$author = $entry->getAuthor();
			$authorName = is_array($author) && isset($author['name']) ? $author['name'] : '';

			$categories = $entry->getCategories()->getValues();

			$content = $entry->getContent();
			$description = $entry->getDescription();

			$dateModified = $entry->getDateModified();
			$dateCreated = $entry->getDateCreated();
			$dateStr = '';
			if ($dateModified !== null) {
				$dateStr = $dateModified->format('c');
			} elseif ($dateCreated !== null) {
				$dateStr = $dateCreated->format('c');
			}

			$imageUrl = $this->extractImageUrl($entry);

			$entries[] = [
				'title'      => $entry->getTitle(),
				'date'       => $dateStr,
				'author'     => $authorName,
				'summary'    => $description !== '' ? mb_substr(strip_tags((string)$description), 0, 200) : '',
				'categories' => $categories,
				'hasContent' => $content !== '',
				'hasImage'   => $imageUrl !== null,
				'link'       => $entry->getLink(),
			];
		}

		$this->logger->info(sprintf('RSS analysis completed: %d entries found', count($entries)));

		return [
			'feed'    => $feedData,
			'entries' => $entries,
		];
	}

	/**
	 * Import RSS/Atom feed entries into a collection via the job queue.
	 *
	 * @param array{draft?: bool, fieldMap?: array<string,string>} $options
	 */
	public function import(string $feedUrl, string $collection, array $options = []): int
	{
		$this->importCount = 0;
		$isDraft = $options['draft'] ?? true;
		/** @var array<string,string> $fieldMap */
		$fieldMap = $options['fieldMap'] ?? [];

		$this->logger->info(sprintf('Starting RSS import from %s into collection %s', $feedUrl, $collection));

		if (!$this->collectionFetcher->collectionExists($collection)) {
			throw new \RuntimeException(sprintf('Collection "%s" does not exist', $collection));
		}

		$feed = $this->fetchFeed($feedUrl);

		foreach ($feed as $entry) {
			/** @var EntryInterface $entry */
			$this->importEntry($entry, $collection, $isDraft, $fieldMap);
		}

		$this->logger->info(sprintf('RSS import completed. Total items queued: %d', $this->importCount));

		return $this->importCount;
	}

	/**
	 * Fetch and parse a remote RSS/Atom feed using Guzzle.
	 *
	 * @return \Laminas\Feed\Reader\Feed\FeedInterface<EntryInterface>
	 */
	private function fetchFeed(string $feedUrl): \Laminas\Feed\Reader\Feed\FeedInterface
	{
		$client = new Client(['timeout' => 30, 'verify' => false]);
		$response = $client->get($feedUrl);

		if ($response->getStatusCode() !== 200) {
			throw new \RuntimeException(sprintf('Failed to fetch feed: HTTP %d', $response->getStatusCode()));
		}

		$xml = $response->getBody()->getContents();
		if (trim($xml) === '') {
			throw new \RuntimeException('Feed returned empty response');
		}

		return Reader::importString($xml);
	}

	/**
	 * @param array<string,string> $fieldMap
	 */
	private function importEntry(EntryInterface $entry, string $collection, bool $isDraft, array $fieldMap): void
	{
		try {
			$title = $entry->getTitle();
			if ($title === '') {
				$title = 'Untitled';
			}
			$id = $this->slugify($title);

			// Build data from RSS entry
			$rssData = $this->extractEntryData($entry);

			// Apply field mapping or use defaults
			$data = $this->mapFields($rssData, $fieldMap);
			$data['id'] = $id;
			$data['draft'] = $isDraft;

			// Handle image download
			$imageUrl = $this->extractImageUrl($entry);
			if ($imageUrl !== null) {
				$tempPath = $this->downloadImage($imageUrl);
				if ($tempPath !== null) {
					$mappedImageField = $fieldMap['image'] ?? 'image';
					$data[$mappedImageField] = $tempPath;
				}
			}

			$this->jobQueuer->queueImport($collection, $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued RSS entry import: %s/%s', $collection, $id));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing RSS entry "%s": %s', $entry->getTitle(), $e->getMessage()));
		}
	}

	/**
	 * Extract all available data from an RSS entry.
	 *
	 * @return array<string,mixed>
	 */
	private function extractEntryData(EntryInterface $entry): array
	{
		$content = $entry->getContent();
		$description = $entry->getDescription();

		$author = $entry->getAuthor();
		$authorName = is_array($author) && isset($author['name']) ? $author['name'] : '';

		$categories = $entry->getCategories()->getValues();

		$dateModified = $entry->getDateModified();
		$dateCreated = $entry->getDateCreated();
		$date = $dateModified ?? $dateCreated;

		$data = [
			'title'      => $entry->getTitle() !== '' ? $entry->getTitle() : 'Untitled',
			'link'       => $entry->getLink(),
			'author'     => $authorName,
			'categories' => $categories,
			'date'       => $date !== null ? $date->format('c') : '',
		];

		// Prefer full content, use description as summary
		if ($content !== '') {
			$data['content'] = $content;
			if ($description !== '') {
				$data['summary'] = $description;
			}
		} elseif ($description !== '') {
			$data['content'] = $description;
		}

		return $data;
	}

	/**
	 * Map RSS fields to collection fields using provided mapping or defaults.
	 *
	 * @param array<string,mixed> $rssData
	 * @param array<string,string> $fieldMap
	 *
	 * @return array<string,mixed>
	 */
	private function mapFields(array $rssData, array $fieldMap): array
	{
		// Default mapping (RSS field => collection field)
		$defaults = [
			'title'      => 'title',
			'content'    => 'content',
			'summary'    => 'summary',
			'date'       => 'date',
			'author'     => 'author',
			'categories' => 'categories',
			'link'       => 'media',
		];

		$mapping = array_merge($defaults, $fieldMap);
		$mapped = [];

		foreach ($mapping as $rssField => $collectionField) {
			if ($collectionField === '' || !isset($rssData[$rssField])) {
				continue;
			}
			$mapped[$collectionField] = $rssData[$rssField];
		}

		return $mapped;
	}

	/**
	 * Extract image URL from RSS entry enclosures or media elements.
	 */
	private function extractImageUrl(EntryInterface $entry): ?string
	{
		// Check enclosure
		$enclosure = $entry->getEnclosure();
		if ($enclosure !== null && isset($enclosure->url)) {
			$type = $enclosure->type ?? '';
			if ($type === '' || str_starts_with((string)$type, 'image/')) {
				return (string)$enclosure->url;
			}
		}

		// Try to extract from content via media:content or media:thumbnail in the XML
		if (!$entry instanceof AbstractEntry) {
			return null;
		}

		$entryElement = $entry->getElement();
		$ownerDocument = $entryElement->ownerDocument;
		if ($ownerDocument === null) {
			return null;
		}

		$xpath = new \DOMXPath($ownerDocument);

		// Register media namespace
		$xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');

		// Try media:content
		$mediaNodes = $xpath->query('.//media:content[@url]', $entryElement);
		if ($mediaNodes !== false && $mediaNodes->length > 0) {
			$node = $mediaNodes->item(0);
			if ($node instanceof \DOMElement) {
				$medium = $node->getAttribute('medium');
				$type = $node->getAttribute('type');
				if ($medium === 'image' || $medium === '' || str_starts_with($type, 'image/')) {
					return $node->getAttribute('url');
				}
			}
		}

		// Try media:thumbnail
		$thumbNodes = $xpath->query('.//media:thumbnail[@url]', $entryElement);
		if ($thumbNodes !== false && $thumbNodes->length > 0) {
			$node = $thumbNodes->item(0);
			if ($node instanceof \DOMElement) {
				return $node->getAttribute('url');
			}
		}

		return null;
	}

	/**
	 * Download an image to a temporary file.
	 */
	private function downloadImage(string $url): ?string
	{
		try {
			$client = new Client(['timeout' => 15, 'verify' => false]);
			$response = $client->get($url);

			if ($response->getStatusCode() !== 200) {
				$this->logger->warning(sprintf('Failed to download image: %s (status %d)', $url, $response->getStatusCode()));

				return null;
			}

			// Determine extension from URL or content type
			$contentType = $response->getHeaderLine('Content-Type');
			$ext = $this->extensionFromContentType($contentType);
			if ($ext === null) {
				$urlPath = parse_url($url, PHP_URL_PATH);
				$pathInfo = pathinfo(is_string($urlPath) ? $urlPath : '');
				$ext = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? $pathInfo['extension'] : 'jpg';
			}

			$tempFile = sys_get_temp_dir() . '/rss-import-' . uniqid() . '.' . $ext;
			file_put_contents($tempFile, $response->getBody()->getContents());

			$this->logger->info(sprintf('Downloaded image: %s → %s', $url, $tempFile));

			return $tempFile;
		} catch (\Exception $e) {
			$this->logger->warning(sprintf('Error downloading image %s: %s', $url, $e->getMessage()));

			return null;
		}
	}

	/**
	 * Map content type to file extension.
	 */
	private function extensionFromContentType(string $contentType): ?string
	{
		$map = [
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => 'svg',
			'image/avif' => 'avif',
		];

		foreach ($map as $mime => $ext) {
			if (str_contains($contentType, $mime)) {
				return $ext;
			}
		}

		return null;
	}

	/**
	 * Convert a title string into a URL-safe slug.
	 */
	private function slugify(string $text): string
	{
		$text = (string)preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
		$text = (string)preg_replace('/[\s-]+/', '-', $text);
		$text = trim($text, '-');
		$text = mb_strtolower($text);

		if ($text === '') {
			$text = 'untitled-' . uniqid();
		}

		return $text;
	}
}
