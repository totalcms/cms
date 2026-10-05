<?php

namespace TotalCMS\Domain\Feed\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Feed\Exception\FeedDisabledException;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Support\Config;

/**
 * The `/feed/rss/{collection}` endpoint: pick a collection's objects, map
 * fields by name, and hand them to {@see FeedWriter} — the same engine
 * `cms.feed.rss()` uses, so the two endpoints can never render the same post
 * differently.
 *
 * A collection publishes a feed only when its RSS Feed card is enabled. The
 * card holds the feed's settings; the request may override only
 * {@see OVERRIDES}. Before the card existed every collection with a URL had a
 * feed, and the query string chose its fields — which made every indexed
 * field of every such collection public.
 */
class RssBuilder
{
	public const DEFAULT_FIELD_MAP = [
		'title'   => 'title',
		'content' => 'summary',
		'media'   => 'media',
		'author'  => 'author',
		'date'    => 'updated',
		// Not an item slot: the property that keeps an object out of the feed.
		'hidden'  => 'draft',
	];

	/** Schemas whose collections publish a feed without being asked: created that way, and enabled by the upgrade migration. */
	public const FEED_SCHEMAS = ['blog', 'blog-legacy', 'feed'];

	/**
	 * What a request may override on a collection that publishes its feed: a
	 * filtered variant (one category, a shorter list) under its own name.
	 * Field mapping is not here — it would let any visitor point `content` at
	 * any indexed field.
	 */
	public const OVERRIDES = ['include', 'exclude', 'limit', 'name', 'description'];

	private const META_KEYS = ['name', 'description', 'link', 'image', 'language'];

	public function __construct(
		private readonly IndexFilter $indexFilter,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectUrlBuilder $objectUrlBuilder,
		private readonly Config $config,
		private readonly FeedWriter $writer,
	) {
	}

	/**
	 * @param array<string,mixed> $params the request's query parameters, plus `rssurl` (the feed's own URL)
	 *
	 * @throws FeedDisabledException when the collection does not publish a feed
	 */
	public function buildFeed(string $collection, array $params = []): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new FeedDisabledException('Collection not found: ' . $collection);
		}

		$selfUrl  = is_string($params['rssurl'] ?? null) ? $params['rssurl'] : '';
		$settings = $collectionData->feed;

		if (empty($settings['enabled'])) {
			throw new FeedDisabledException(sprintf('RSS feed is not enabled for collection: %s', $collection));
		}

		// Saved settings are the feed; the request may only narrow or rename it.
		$query    = array_filter(array_intersect_key($params, array_flip(self::OVERRIDES)), is_string(...));
		$options  = array_merge($this->savedOptions($settings), $this->filled($query));
		$fieldMap = $this->fieldMap($settings);

		// Extract limit (default: 25, 0 or -1 means no limit)
		$limit = isset($options['limit']) ? (int)$options['limit'] : 25;

		$meta = $this->meta($options, $selfUrl);

		// Fetch and filter objects
		$objects = $this->indexFilter->fetchFilteredIndex($collection, array_intersect_key($options, array_flip(['include', 'exclude'])));

		// Hidden objects — drafts, unless the card names another property —
		// never reach the feed. Done here, in code, for every schema: as an
		// `exclude` default it applied to the blog schemas only and was
		// replaced by any filter the request carried.
		$objects = array_filter($objects, fn (array $object): bool => !$this->isHidden($object, $fieldMap['hidden']));

		// Sort by date (newest first)
		usort($objects, static function (array $a, array $b) use ($fieldMap): int {
			$dateA = $a[$fieldMap['date']] ?? 0;
			$dateB = $b[$fieldMap['date']] ?? 0;

			return strtotime($dateB) <=> strtotime($dateA);
		});

		// Apply limit (-1 means no limit)
		if ($limit > 0) {
			$objects = array_slice($objects, 0, $limit);
		}

		$items = [];
		foreach ($objects as $object) {
			$url = $this->objectUrlBuilder->buildUrl($collectionData, $object);

			// Skip objects with broken URLs (empty segments from missing template data)
			if ($url === '' || $this->objectUrlBuilder->hasEmptySegments($url)) {
				continue;
			}

			$items[] = $this->item($object, $url, $fieldMap);
		}

		return $this->writer->write($meta, $items, 'rss');
	}

	/**
	 * Which object field feeds each item slot: {@see DEFAULT_FIELD_MAP}, with
	 * any property the collection's saved card names for one of its keys.
	 *
	 * @param array<string,mixed> $source
	 *
	 * @return array<string,string>
	 */
	private function fieldMap(array $source): array
	{
		$map = self::DEFAULT_FIELD_MAP;

		foreach (array_keys($map) as $key) {
			$field = $source[$key] ?? null;
			if (is_string($field) && $field !== '') {
				$map[$key] = $field;
			}
		}

		return $map;
	}

	/**
	 * The saved card's feed-level settings, as request-shaped options. Blank
	 * values are left out so the defaults apply.
	 *
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,string>
	 */
	private function savedOptions(array $settings): array
	{
		$options = [];
		foreach ([...self::META_KEYS, 'include', 'exclude', 'limit'] as $key) {
			$options[$key] = $this->scalar($settings[$key] ?? '');
		}

		return $this->filled($options);
	}

	/**
	 * @param array<string,string> $options
	 *
	 * @return array<string,string>
	 */
	private function filled(array $options): array
	{
		return array_filter($options, static fn (string $value): bool => trim($value) !== '');
	}

	private function scalar(mixed $value): string
	{
		return is_scalar($value) ? (string)$value : '';
	}

	/**
	 * Whether an index row stays out of the feed: its hidden property is on.
	 * That property is `draft` unless the collection's saved card maps Hidden
	 * Field to another one — which replaces `draft`, so a collection can
	 * choose to publish its drafts. It is never read from the request: were it
	 * settable there, any visitor could switch the filter off. A property the
	 * schema does not index is not covered, since the feed reads index rows.
	 *
	 * @param array<string,mixed> $object
	 */
	private function isHidden(array $object, string $hiddenField): bool
	{
		return filter_var($object[$hiddenField] ?? false, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * One object as a FeedWriter item, through the field map.
	 *
	 * @param array<string,mixed>  $object
	 * @param array<string,string> $fieldMap
	 *
	 * @return array<string,mixed>
	 */
	private function item(array $object, string $url, array $fieldMap): array
	{
		$id    = (string)$object['id'];
		$title = $object[$fieldMap['title']] ?? '';
		$date  = $object[$fieldMap['date']] ?? null;

		return [
			'id'      => $id,
			// Laminas requires a title; the id is the fallback
			'title'   => is_string($title) && $title !== '' ? $title : $id,
			'link'    => $url,
			'date'    => $date ?? time(),
			'summary' => $object[$fieldMap['content']] ?? '',
			'author'  => $object[$fieldMap['author']] ?? '',
			'media'   => $object[$fieldMap['media']] ?? '',
		];
	}

	/**
	 * Feed-level meta from the resolved options, with the site's name and
	 * homepage as the defaults.
	 *
	 * @param array<string,string> $options
	 *
	 * @return array<string,mixed>
	 */
	private function meta(array $options, string $selfUrl): array
	{
		$defaultLink = $this->homepage();
		// Ensure we have a valid link (Laminas requires valid URI)
		if (in_array($defaultLink, ['', '0', 'http://'], true)) {
			$defaultLink = 'https://' . ($this->config->domain ?: 'localhost');
		}

		$name = (string)($options['name'] ?? (($this->config->displayName() ?: $this->domainName() ?: 'RSS') . ' Feed'));

		return [
			'title'       => $name,
			'link'        => (string)($options['link'] ?? $defaultLink),
			// Laminas requires a description
			'description' => (string)(($options['description'] ?? '') ?: $name),
			'self'        => $selfUrl,
			'image'       => (string)($options['image'] ?? ''),
			'language'    => (string)($options['language'] ?? ''),
			'generator'   => 'Total CMS',
			'updated'     => time(),
		];
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	private function domainName(): string
	{
		return $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	private function homepage(): string
	{
		return sprintf(
			'%s://%s',
			$_SERVER['REQUEST_SCHEME'] ?? 'http',
			$this->domainName(),
		);
	}
}
