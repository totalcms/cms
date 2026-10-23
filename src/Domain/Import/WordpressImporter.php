<?php

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Import\Concerns\QueuesImports;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

class WordpressImporter
{
	use QueuesImports;

	private readonly LoggerInterface $logger;

	/** @var array<string,string> Resolved WXR namespace URIs (populated per-document) */
	private array $ns = [
		'wp'      => 'http://wordpress.org/export/1.2/',
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
		'dc'      => 'http://purl.org/dc/elements/1.1/',
	];

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly JobQueuer $jobQueuer,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::WordpressImporter);
	}

	/**
	 * Analyze a WXR XML export and return a preview of its contents.
	 *
	 * @return array{posts: int, dateRange: array{earliest: string, latest: string}, categories: array<int,string>, tags: array<int,string>, authors: array<int,string>, sample: array<int,array<string,mixed>>}
	 */
	public function analyze(string $xmlContent): array
	{
		$this->logger->info('Starting WordPress WXR analysis');

		$xml         = $this->parseXml($xmlContent);
		$authorNames = $this->buildAuthorLookup($xml);

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
			$login   = (string)$dcNs->creator;
			$author  = $authorNames[$login] ?? $login;
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
				'category' => implode(', ', $this->itemTerms($item, 'category')),
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
		$authors     = $this->buildAuthorLookup($xml);

		foreach ($xml->channel->item as $item) {
			$wpNs     = $item->children($this->ns['wp']);
			$postType = (string)$wpNs->post_type;

			if ($postType !== 'post') {
				continue;
			}

			$this->importPost($item, $collection, $isDraft, $attachments, $authors);
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

	/**
	 * Map each author login to the name WordPress displays for them.
	 *
	 * @return array<string,string>
	 */
	private function buildAuthorLookup(\SimpleXMLElement $xml): array
	{
		$authors = [];

		foreach ($xml->channel->children($this->ns['wp'])->author as $author) {
			$login       = (string)$author->author_login;
			$displayName = trim((string)$author->author_display_name);

			if ($login !== '' && $displayName !== '') {
				$authors[$login] = $displayName;
			}
		}

		return $authors;
	}

	// ─── Post Import ───────────────────────────────────────────

	/**
	 * Import a single WordPress post.
	 *
	 * @param array<string,string> $attachments
	 * @param array<string,string> $authors
	 */
	private function importPost(\SimpleXMLElement $item, string $collection, bool $isDraft, array $attachments, array $authors): void
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
			$body    = $this->stripBlockComments((string)$contentNs->encoded);
			$summary = (string)$excerptNs->encoded;
			$login   = (string)$dcNs->creator;
			$link    = (string)$item->link;

			$data = [
				'id'         => $slug,
				'title'      => $title,
				'content'    => $body,
				'summary'    => $summary,
				'date'       => $date,
				'author'     => $authors[$login] ?? $login,
				'categories' => $this->itemTerms($item, 'category'),
				'tags'       => $this->itemTerms($item, 'post_tag'),
				'draft'      => $draft,
				'url'        => $link,
			];

			// Featured image: queued as a URL and downloaded by the import job,
			// so the request never waits on the remote server.
			$thumbnailId = $this->extractThumbnailId($wpNs);
			if ($thumbnailId !== null && isset($attachments[$thumbnailId])) {
				$data[RemoteMediaFetcher::PAYLOAD_KEY] = ['image' => $attachments[$thumbnailId]];
			}

			$this->queueObject($collection, $data, sprintf('Queued WordPress post import: %s/%s', $collection, $slug));
		} catch (\Exception $e) {
			$title = (string)$item->title;
			$this->logger->error(sprintf('Error importing WordPress post "%s": %s', $title, $e->getMessage()));
		}
	}

	// ─── Field Extraction ──────────────────────────────────────

	/**
	 * The item's category or tag names, as the list the blog schema's
	 * `categories` and `tags` fields store. A list rather than a joined
	 * string, so a name containing a comma stays one term.
	 *
	 * @param string $domain `category` or `post_tag`
	 *
	 * @return list<string>
	 */
	private function itemTerms(\SimpleXMLElement $item, string $domain): array
	{
		$terms = [];
		foreach ($item->category as $cat) {
			$name = trim((string)$cat);
			if ((string)$cat['domain'] !== $domain || $name === '' || in_array($name, $terms, true)) {
				continue;
			}
			if ($domain === 'category' && $name === 'Uncategorized') {
				continue;
			}
			$terms[] = $name;
		}

		return $terms;
	}

	/**
	 * Drop the block editor's `<!-- wp:… -->` / `<!-- /wp:… -->` markers.
	 * They only mean something to Gutenberg; the HTML between them is the
	 * content.
	 */
	private function stripBlockComments(string $html): string
	{
		return (string)preg_replace('#<!--\s*/?wp:.*?-->\n?#s', '', $html);
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
	 * Convert a title string into a URL-safe slug.
	 */
	private function slugify(string $text): string
	{
		// The same slug every other importer and the id field mint from a
		// title, so an imported entry's id matches what T3 would derive itself.
		$slug = SlugData::slugify($text);

		return $slug === '' ? 'untitled-' . uniqid() : $slug;
	}
}
