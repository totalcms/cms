<?php

namespace TotalCMS\Domain\Import;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Factory\LoggerFactory;

class WordpressImporter
{
	private readonly LoggerInterface $logger;
	private int $importCount = 0;

	/** @var array<string,string> Resolved WXR namespace URIs (populated per-document) */
	private array $ns = [
		'wp'      => 'http://wordpress.org/export/1.2/',
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'excerpt' => 'http://wordpress.org/export/1.2/',
		'dc'      => 'http://purl.org/dc/elements/1.1/',
	];

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('wordpress-importer');
	}

	/**
	 * Analyze a WXR XML export and return a preview of its contents.
	 *
	 * @return array{posts: int, dateRange: array{earliest: string, latest: string}, categories: array<int,string>, tags: array<int,string>, authors: array<int,string>, sample: array<int,array<string,mixed>>}
	 */
	public function analyze(string $xmlContent): array
	{
		$this->logger->info('Starting WordPress WXR analysis');

		$xml = $this->parseXml($xmlContent);

		$posts      = [];
		$categories = [];
		$tags       = [];
		$authors    = [];
		$dates      = [];

		foreach ($xml->channel->item as $item) {
			$wpNs      = $item->children($this->ns['wp']);
			$postType  = (string)$wpNs->post_type;

			if ($postType !== 'post') {
				continue;
			}

			$dcNs    = $item->children($this->ns['dc']);
			$author  = (string)$dcNs->creator;
			if ($author !== '' && !in_array($author, $authors, true)) {
				$authors[] = $author;
			}

			$postDate = (string)$wpNs->post_date;
			if ($postDate !== '' && $postDate !== '0000-00-00 00:00:00') {
				$dates[] = $postDate;
			}

			$status = (string)$wpNs->status;

			// Extract categories and tags from this item
			foreach ($item->category as $cat) {
				$domain = (string)$cat['domain'];
				$name   = (string)$cat;
				if ($domain === 'category' && $name !== '' && !in_array($name, $categories, true)) {
					$categories[] = $name;
				}
				if ($domain === 'post_tag' && $name !== '' && !in_array($name, $tags, true)) {
					$tags[] = $name;
				}
			}

			$posts[] = [
				'title'    => (string)$item->title,
				'date'     => $postDate,
				'status'   => $status,
				'category' => $this->extractItemCategories($item),
				'author'   => $author,
			];
		}

		sort($dates);
		$earliest = $dates[0] ?? '';
		$latest   = end($dates) !== false ? end($dates) : '';

		// Limit sample to first 10 posts
		$sample = array_slice($posts, 0, 10);

		$this->logger->info(sprintf('WordPress WXR analysis completed: %d posts found', count($posts)));

		return [
			'posts'     => count($posts),
			'dateRange' => [
				'earliest' => $earliest,
				'latest'   => $latest,
			],
			'categories' => $categories,
			'tags'       => $tags,
			'authors'    => $authors,
			'sample'     => $sample,
		];
	}

	/**
	 * Import WordPress posts from WXR XML into a collection via the job queue.
	 *
	 * @param array{draft?: bool} $options
	 */
	public function import(string $xmlContent, string $collection, array $options = []): int
	{
		$this->importCount = 0;
		$isDraft           = $options['draft'] ?? true;

		$this->logger->info(sprintf('Starting WordPress import into collection %s', $collection));

		if (!$this->collectionFetcher->collectionExists($collection)) {
			throw new \RuntimeException(sprintf('Collection "%s" does not exist', $collection));
		}

		$xml = $this->parseXml($xmlContent);

		// Build attachment lookup: post_id → attachment URL (for featured images)
		$attachments = $this->buildAttachmentLookup($xml);

		foreach ($xml->channel->item as $item) {
			$wpNs     = $item->children($this->ns['wp']);
			$postType = (string)$wpNs->post_type;

			if ($postType !== 'post') {
				continue;
			}

			$this->importPost($item, $collection, $isDraft, $attachments);
		}

		$this->logger->info(sprintf('WordPress import completed. Total items queued: %d', $this->importCount));

		return $this->importCount;
	}

	// ─── XML Parsing ───────────────────────────────────────────

	/**
	 * Parse WXR XML content into a SimpleXMLElement.
	 */
	private function parseXml(string $xmlContent): \SimpleXMLElement
	{
		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($xmlContent, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_NONET);

		if ($xml === false) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$message = 'Failed to parse WXR XML';
			if (count($errors) > 0) {
				$message .= ': ' . $errors[0]->message;
			}
			throw new \RuntimeException($message);
		}

		// Detect actual namespace URIs from the document
		$namespaces = $xml->getNamespaces(true);
		foreach (['wp', 'content', 'excerpt', 'dc'] as $prefix) {
			if (isset($namespaces[$prefix])) {
				$this->ns[$prefix] = $namespaces[$prefix];
			}
		}

		return $xml;
	}

	// ─── Attachment Lookup ──────────────────────────────────────

	/**
	 * Build a lookup table of attachment post IDs to their URLs.
	 *
	 * @return array<string,string>
	 */
	private function buildAttachmentLookup(\SimpleXMLElement $xml): array
	{
		$attachments = [];

		foreach ($xml->channel->item as $item) {
			$wpNs     = $item->children($this->ns['wp']);
			$postType = (string)$wpNs->post_type;

			if ($postType !== 'attachment') {
				continue;
			}

			$postId        = (string)$wpNs->post_id;
			$attachmentUrl = (string)$wpNs->attachment_url;

			if ($postId !== '' && $attachmentUrl !== '') {
				$attachments[$postId] = $attachmentUrl;
			}
		}

		return $attachments;
	}

	// ─── Post Import ───────────────────────────────────────────

	/**
	 * Import a single WordPress post.
	 *
	 * @param array<string,string> $attachments
	 */
	private function importPost(\SimpleXMLElement $item, string $collection, bool $isDraft, array $attachments): void
	{
		try {
			$wpNs      = $item->children($this->ns['wp']);
			$contentNs = $item->children($this->ns['content']);
			$excerptNs = $item->children($this->ns['excerpt']);
			$dcNs      = $item->children($this->ns['dc']);

			$title = (string)$item->title;
			if ($title === '') {
				$title = 'Untitled';
			}

			// Use wp:post_name for slug, fall back to title slugification
			$slug = (string)$wpNs->post_name;
			if ($slug === '') {
				$slug = $this->slugify($title);
			}

			// Date handling
			$postDate = (string)$wpNs->post_date;
			$date     = '';
			if ($postDate !== '' && $postDate !== '0000-00-00 00:00:00') {
				try {
					$dt   = new \DateTimeImmutable($postDate);
					$date = $dt->format('c');
				} catch (\Exception) {
					$date = $postDate;
				}
			}

			// Status → draft flag
			$status  = (string)$wpNs->status;
			$draft   = $isDraft || $status !== 'publish';

			// Content
			$body    = (string)$contentNs->encoded;
			$summary = (string)$excerptNs->encoded;
			$author  = (string)$dcNs->creator;
			$link    = (string)$item->link;

			// Categories and tags
			$itemCategories = $this->extractItemCategories($item);
			$itemTags       = $this->extractItemTags($item);

			$data = [
				'id'       => $slug,
				'title'    => $title,
				'content'  => $body,
				'summary'  => $summary,
				'date'     => $date,
				'author'   => $author,
				'category' => $itemCategories,
				'tags'     => $itemTags,
				'draft'    => $draft,
				'url'      => $link,
			];

			// Featured image
			$thumbnailId = $this->extractThumbnailId($wpNs);
			if ($thumbnailId !== null && isset($attachments[$thumbnailId])) {
				$imageUrl = $attachments[$thumbnailId];
				$tempPath = $this->downloadImage($imageUrl);
				if ($tempPath !== null) {
					$data['image'] = $tempPath;
				}
			}

			$this->jobQueuer->queueImport($collection, $data);
			$this->importCount++;
			$this->logger->info(sprintf('Queued WordPress post import: %s/%s', $collection, $slug));
		} catch (\Exception $e) {
			$title = (string)$item->title;
			$this->logger->error(sprintf('Error importing WordPress post "%s": %s', $title, $e->getMessage()));
		}
	}

	// ─── Field Extraction ──────────────────────────────────────

	/**
	 * Extract category names from an item.
	 */
	private function extractItemCategories(\SimpleXMLElement $item): string
	{
		$categories = [];
		foreach ($item->category as $cat) {
			$domain = (string)$cat['domain'];
			$name   = (string)$cat;
			if ($domain === 'category' && $name !== '' && $name !== 'Uncategorized') {
				$categories[] = $name;
			}
		}

		return implode(', ', $categories);
	}

	/**
	 * Extract tag names from an item.
	 */
	private function extractItemTags(\SimpleXMLElement $item): string
	{
		$tags = [];
		foreach ($item->category as $cat) {
			$domain = (string)$cat['domain'];
			$name   = (string)$cat;
			if ($domain === 'post_tag' && $name !== '') {
				$tags[] = $name;
			}
		}

		return implode(', ', $tags);
	}

	/**
	 * Extract the featured image (thumbnail) attachment ID from post meta.
	 */
	private function extractThumbnailId(\SimpleXMLElement $wpNs): ?string
	{
		foreach ($wpNs->postmeta as $meta) {
			$key   = (string)$meta->meta_key;
			$value = (string)$meta->meta_value;
			if ($key === '_thumbnail_id' && $value !== '') {
				return $value;
			}
		}

		return null;
	}

	// ─── Shared Utilities ──────────────────────────────────────

	/**
	 * Download an image to a temporary file.
	 */
	private function downloadImage(string $url): ?string
	{
		try {
			$client   = new Client(['timeout' => 15, 'verify' => false]);
			$response = $client->get($url);

			if ($response->getStatusCode() !== 200) {
				$this->logger->warning(sprintf('Failed to download image: %s (status %d)', $url, $response->getStatusCode()));

				return null;
			}

			$contentType = $response->getHeaderLine('Content-Type');
			$ext         = $this->extensionFromContentType($contentType);
			if ($ext === null) {
				$urlPath  = parse_url($url, PHP_URL_PATH);
				$pathInfo = pathinfo(is_string($urlPath) ? $urlPath : '');
				$ext      = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? $pathInfo['extension'] : 'jpg';
			}

			$tempFile = sys_get_temp_dir() . '/wp-import-' . uniqid() . '.' . $ext;
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
			'image/jpeg'    => 'jpg',
			'image/png'     => 'png',
			'image/gif'     => 'gif',
			'image/webp'    => 'webp',
			'image/svg+xml' => 'svg',
			'image/avif'    => 'avif',
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
