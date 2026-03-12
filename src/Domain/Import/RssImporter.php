<?php

namespace TotalCMS\Domain\Import;

use Laminas\Feed\Reader\Entry\AbstractEntry;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\Reader;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;

class RssImporter
{
	private readonly LoggerInterface $logger;
	private int $importCount = 0;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly JobQueuer $jobQueuer,
		private readonly HttpClientInterface $httpClient,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('rss-importer');
	}

	/**
	 * Analyze an RSS/Atom/JSON feed and return a preview of its contents.
	 *
	 * @return array{feed: array<string,mixed>, entries: array<int,array<string,mixed>>}
	 */
	public function analyze(string $feedUrl): array
	{
		$this->logger->info(sprintf('Starting feed analysis: %s', $feedUrl));

		$raw = $this->fetchRawFeed($feedUrl);

		if ($this->isJsonFeed($raw)) {
			return $this->analyzeJsonFeed($raw);
		}

		return $this->analyzeXmlFeed($raw);
	}

	/**
	 * Import feed entries into a collection via the job queue.
	 *
	 * @param array{draft?: bool, fieldMap?: array<string,string>} $options
	 */
	public function import(string $feedUrl, string $collection, array $options = []): int
	{
		$this->importCount = 0;
		$isDraft           = $options['draft'] ?? true;
		/** @var array<string,string> $fieldMap */
		$fieldMap = $options['fieldMap'] ?? [];

		$this->logger->info(sprintf('Starting feed import from %s into collection %s', $feedUrl, $collection));

		if (!$this->collectionFetcher->collectionExists($collection)) {
			throw new \RuntimeException(sprintf('Collection "%s" does not exist', $collection));
		}

		$raw = $this->fetchRawFeed($feedUrl);

		if ($this->isJsonFeed($raw)) {
			$this->importJsonFeed($raw, $collection, $isDraft, $fieldMap);
		} else {
			$this->importXmlFeed($raw, $collection, $isDraft, $fieldMap);
		}

		$this->logger->info(sprintf('Feed import completed. Total items queued: %d', $this->importCount));

		return $this->importCount;
	}

	// ─── Feed Fetching ──────────────────────────────────────────

	/**
	 * Fetch raw feed content from a URL.
	 */
	private function fetchRawFeed(string $feedUrl): string
	{
		try {
			$response = $this->httpClient->request('GET', $feedUrl, [
				'timeout'          => 30,
				'verify_ssl'       => false,
				'follow_redirects' => true,
			]);
		} catch (\RuntimeException $e) {
			throw new \RuntimeException(sprintf('Failed to fetch feed from %s: %s', $feedUrl, $e->getMessage()), 0, $e);
		}

		if ($response->statusCode !== 200) {
			throw new \RuntimeException(sprintf('Failed to fetch feed: HTTP %d', $response->statusCode));
		}

		if (trim($response->body) === '') {
			throw new \RuntimeException('Feed returned empty response');
		}

		return $response->body;
	}

	/**
	 * Detect whether the raw content is a JSON Feed.
	 */
	private function isJsonFeed(string $raw): bool
	{
		$trimmed = ltrim($raw);

		if (!str_starts_with($trimmed, '{')) {
			return false;
		}

		$decoded = json_decode($trimmed, true);

		return is_array($decoded) && isset($decoded['version']) && str_contains((string)$decoded['version'], 'jsonfeed');
	}

	// ─── XML (RSS/Atom) Feed Handling ───────────────────────────

	/**
	 * @return array{feed: array<string,mixed>, entries: array<int,array<string,mixed>>}
	 */
	private function analyzeXmlFeed(string $xml): array
	{
		$feed = Reader::importString($xml);

		$feedData = [
			'title'       => $feed->getTitle() ?? '',
			'description' => $feed->getDescription() ?? '',
			'link'        => $feed->getLink() ?? '',
			'count'       => $feed->count(),
		];

		$entries = [];
		foreach ($feed as $entry) {
			/** @var EntryInterface $entry */
			$author     = $entry->getAuthor();
			$authorName = is_array($author) && isset($author['name']) ? $author['name'] : '';

			$categories = $entry->getCategories()->getValues();

			$content     = $entry->getContent();
			$description = $entry->getDescription();

			$dateModified = $entry->getDateModified();
			$dateCreated  = $entry->getDateCreated();
			$dateStr      = '';
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

		$this->logger->info(sprintf('XML feed analysis completed: %d entries found', count($entries)));

		return [
			'feed'    => $feedData,
			'entries' => $entries,
		];
	}

	/**
	 * @param array<string,string> $fieldMap
	 */
	private function importXmlFeed(string $xml, string $collection, bool $isDraft, array $fieldMap): void
	{
		$feed = Reader::importString($xml);

		foreach ($feed as $entry) {
			/** @var EntryInterface $entry */
			$this->importXmlEntry($entry, $collection, $isDraft, $fieldMap);
		}
	}

	/**
	 * @param array<string,string> $fieldMap
	 */
	private function importXmlEntry(EntryInterface $entry, string $collection, bool $isDraft, array $fieldMap): void
	{
		try {
			$title = $entry->getTitle();
			if ($title === '') {
				$title = 'Untitled';
			}
			$id = $this->slugify($title);

			$rssData = $this->extractXmlEntryData($entry);

			$data          = $this->mapFields($rssData, $fieldMap);
			$data['id']    = $id;
			$data['draft'] = $isDraft;

			$imageUrl = $this->extractImageUrl($entry);
			if ($imageUrl !== null) {
				$tempPath = $this->downloadImage($imageUrl);
				if ($tempPath !== null) {
					$mappedImageField        = $fieldMap['image'] ?? 'image';
					$data[$mappedImageField] = $tempPath;
				}
			}

			$this->jobQueuer->queueImport($collection, $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued feed entry import: %s/%s', $collection, $id));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing feed entry "%s": %s', $entry->getTitle(), $e->getMessage()));
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extractXmlEntryData(EntryInterface $entry): array
	{
		$content     = $entry->getContent();
		$description = $entry->getDescription();

		$author     = $entry->getAuthor();
		$authorName = is_array($author) && isset($author['name']) ? $author['name'] : '';

		$categories = $entry->getCategories()->getValues();

		$dateModified = $entry->getDateModified();
		$dateCreated  = $entry->getDateCreated();
		$date         = $dateModified ?? $dateCreated;

		$data = [
			'title'      => $entry->getTitle() !== '' ? $entry->getTitle() : 'Untitled',
			'link'       => $entry->getLink(),
			'author'     => $authorName,
			'categories' => $categories,
			'date'       => $date !== null ? $date->format('c') : '',
		];

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

	// ─── JSON Feed Handling ─────────────────────────────────────

	/**
	 * @return array{feed: array<string,mixed>, entries: array<int,array<string,mixed>>}
	 */
	private function analyzeJsonFeed(string $json): array
	{
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \RuntimeException('Invalid JSON Feed structure');
		}

		/** @var array<int,mixed> $items */
		$items = $data['items'] ?? [];

		$feedData = [
			'title'       => (string)($data['title'] ?? ''),
			'description' => (string)($data['description'] ?? ''),
			'link'        => (string)($data['home_page_url'] ?? ''),
			'count'       => count($items),
		];

		$entries = [];
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$authorName = $this->extractJsonAuthor($item, $data);
			$content    = (string)($item['content_html'] ?? $item['content_text'] ?? '');
			$summary    = (string)($item['summary'] ?? '');
			$imageUrl   = $this->extractJsonImageUrl($item);

			$tags = [];
			if (isset($item['tags']) && is_array($item['tags'])) {
				$tags = array_map(strval(...), $item['tags']);
			}

			$entries[] = [
				'title'      => (string)($item['title'] ?? ''),
				'date'       => (string)($item['date_published'] ?? $item['date_modified'] ?? ''),
				'author'     => $authorName,
				'summary'    => $summary !== '' ? mb_substr(strip_tags($summary), 0, 200) : ($content !== '' ? mb_substr(strip_tags($content), 0, 200) : ''),
				'categories' => $tags,
				'hasContent' => $content !== '',
				'hasImage'   => $imageUrl !== null,
				'link'       => (string)($item['url'] ?? $item['external_url'] ?? ''),
			];
		}

		$this->logger->info(sprintf('JSON feed analysis completed: %d entries found', count($entries)));

		return [
			'feed'    => $feedData,
			'entries' => $entries,
		];
	}

	/**
	 * @param array<string,string> $fieldMap
	 */
	private function importJsonFeed(string $json, string $collection, bool $isDraft, array $fieldMap): void
	{
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new \RuntimeException('Invalid JSON Feed structure');
		}

		/** @var array<int,mixed> $items */
		$items = $data['items'] ?? [];

		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}
			$this->importJsonEntry($item, $data, $collection, $isDraft, $fieldMap);
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $feedData
	 * @param array<string,string> $fieldMap
	 */
	private function importJsonEntry(array $item, array $feedData, string $collection, bool $isDraft, array $fieldMap): void
	{
		try {
			$title = (string)($item['title'] ?? '');
			if ($title === '') {
				$title = 'Untitled';
			}
			$id = $this->slugify($title);

			$rssData = $this->extractJsonEntryData($item, $feedData);

			$data          = $this->mapFields($rssData, $fieldMap);
			$data['id']    = $id;
			$data['draft'] = $isDraft;

			$imageUrl = $this->extractJsonImageUrl($item);
			if ($imageUrl !== null) {
				$tempPath = $this->downloadImage($imageUrl);
				if ($tempPath !== null) {
					$mappedImageField        = $fieldMap['image'] ?? 'image';
					$data[$mappedImageField] = $tempPath;
				}
			}

			$this->jobQueuer->queueImport($collection, $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued JSON feed entry import: %s/%s', $collection, $id));
		} catch (\Exception $e) {
			$this->logger->error(sprintf('Error importing JSON feed entry "%s": %s', $item['title'] ?? 'unknown', $e->getMessage()));
		}
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $feedData
	 *
	 * @return array<string,mixed>
	 */
	private function extractJsonEntryData(array $item, array $feedData): array
	{
		$contentHtml = (string)($item['content_html'] ?? '');
		$contentText = (string)($item['content_text'] ?? '');
		$summary     = (string)($item['summary'] ?? '');

		$tags = [];
		if (isset($item['tags']) && is_array($item['tags'])) {
			$tags = array_map(strval(...), $item['tags']);
		}

		$date = (string)($item['date_published'] ?? $item['date_modified'] ?? '');

		$data = [
			'title'      => (string)($item['title'] ?? 'Untitled'),
			'link'       => (string)($item['url'] ?? $item['external_url'] ?? ''),
			'author'     => $this->extractJsonAuthor($item, $feedData),
			'categories' => $tags,
			'date'       => $date,
		];

		// Prefer content_html over content_text
		if ($contentHtml !== '') {
			$data['content'] = $contentHtml;
			if ($summary !== '') {
				$data['summary'] = $summary;
			}
		} elseif ($contentText !== '') {
			$data['content'] = $contentText;
			if ($summary !== '') {
				$data['summary'] = $summary;
			}
		} elseif ($summary !== '') {
			$data['content'] = $summary;
		}

		return $data;
	}

	/**
	 * Extract author name from a JSON Feed item, falling back to feed-level authors.
	 *
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $feedData
	 */
	private function extractJsonAuthor(array $item, array $feedData): string
	{
		// Item-level authors (JSON Feed 1.1)
		if (isset($item['authors']) && is_array($item['authors'])) {
			$first = $item['authors'][0] ?? null;
			if (is_array($first) && isset($first['name'])) {
				return (string)$first['name'];
			}
		}

		// Legacy item-level author (JSON Feed 1.0)
		if (isset($item['author']) && is_array($item['author']) && isset($item['author']['name'])) {
			return (string)$item['author']['name'];
		}

		// Feed-level authors
		if (isset($feedData['authors']) && is_array($feedData['authors'])) {
			$first = $feedData['authors'][0] ?? null;
			if (is_array($first) && isset($first['name'])) {
				return (string)$first['name'];
			}
		}

		// Legacy feed-level author
		if (isset($feedData['author']) && is_array($feedData['author']) && isset($feedData['author']['name'])) {
			return (string)$feedData['author']['name'];
		}

		return '';
	}

	/**
	 * Extract image URL from a JSON Feed item.
	 *
	 * @param array<string,mixed> $item
	 */
	private function extractJsonImageUrl(array $item): ?string
	{
		if (isset($item['image']) && is_string($item['image']) && $item['image'] !== '') {
			return $item['image'];
		}

		if (isset($item['banner_image']) && is_string($item['banner_image']) && $item['banner_image'] !== '') {
			return $item['banner_image'];
		}

		return null;
	}

	// ─── XML Image Extraction ───────────────────────────────────

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

		$entryElement  = $entry->getElement();
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
				$type   = $node->getAttribute('type');
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

	// ─── Shared Utilities ───────────────────────────────────────

	/**
	 * Map feed fields to collection fields using provided mapping or defaults.
	 *
	 * @param array<string,mixed> $rssData
	 * @param array<string,string> $fieldMap
	 *
	 * @return array<string,mixed>
	 */
	private function mapFields(array $rssData, array $fieldMap): array
	{
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
		$mapped  = [];

		foreach ($mapping as $rssField => $collectionField) {
			if ($collectionField === '' || !isset($rssData[$rssField])) {
				continue;
			}
			$mapped[$collectionField] = $rssData[$rssField];
		}

		return $mapped;
	}

	/**
	 * Download an image to a temporary file.
	 */
	private function downloadImage(string $url): ?string
	{
		try {
			$response = $this->httpClient->request('GET', $url, [
				'timeout'          => 15,
				'verify_ssl'       => false,
				'follow_redirects' => true,
			]);

			if ($response->statusCode !== 200) {
				$this->logger->warning(sprintf('Failed to download image: %s (status %d)', $url, $response->statusCode));

				return null;
			}

			$urlPath  = parse_url($url, PHP_URL_PATH);
			$pathInfo = pathinfo(is_string($urlPath) ? $urlPath : '');
			$ext      = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? $pathInfo['extension'] : 'jpg';

			$tempFile = sys_get_temp_dir() . '/rss-import-' . uniqid() . '.' . $ext;
			file_put_contents($tempFile, $response->body);

			$this->logger->info(sprintf('Downloaded image: %s → %s', $url, $tempFile));

			return $tempFile;
		} catch (\Exception $e) {
			$this->logger->warning(sprintf('Error downloading image %s: %s', $url, $e->getMessage()));

			return null;
		}
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
