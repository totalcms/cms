<?php

namespace TotalCMS\Domain\Feed\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Support\Config;

/**
 * The `/feed/rss/{collection}` endpoint: pick a collection's objects by the
 * request's filters, map fields by name, and hand them to {@see FeedWriter}
 * — the same engine `cms.feed.rss()` uses, so the two endpoints can never
 * render the same post differently.
 */
class RssBuilder
{
	public const DEFAULT_FIELD_MAP = [
		'title'   => 'title',
		'content' => 'summary',
		'media'   => 'media',
		'author'  => 'author',
		'date'    => 'updated',
	];

	/** @var array<string,string> */
	private array $fieldMap = self::DEFAULT_FIELD_MAP;

	public function __construct(
		private readonly IndexFilter $indexFilter,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectUrlBuilder $objectUrlBuilder,
		private readonly Config $config,
		private readonly FeedWriter $writer,
	) {
	}

	/**
	 * Remap which object field feeds each item slot. Only the keys of
	 * {@see DEFAULT_FIELD_MAP} are taken, and only non-empty strings — the
	 * caller hands over a whole query string. `draft` is deliberately not one
	 * of them: see {@see isDraft()}.
	 *
	 * @param array<string,mixed> $fieldMap
	 */
	public function setFieldMap(array $fieldMap): void
	{
		$map = self::DEFAULT_FIELD_MAP;

		foreach (array_keys($map) as $key) {
			$field = $fieldMap[$key] ?? null;
			if (is_string($field) && $field !== '') {
				$map[$key] = $field;
			}
		}

		$this->fieldMap = $map;
	}

	/** @param array<string,string> $options */
	public function buildFeed(string $collection, array $options = []): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \Exception('Collection not found: ' . $collection);
		}

		// Extract limit (default: 25, 0 or -1 means no limit)
		$limit = isset($options['limit']) ? (int)$options['limit'] : 25;
		unset($options['limit']);

		$meta = $this->meta($options);

		// Fetch and filter objects
		$objects = $this->indexFilter->fetchFilteredIndex($collection, $options);

		// Drafts never reach a public feed. Done here, in code, for every
		// schema: as an `exclude` default it applied to the blog schemas only
		// and was replaced by any include/exclude the request carried.
		$objects = array_filter($objects, fn (array $object): bool => !$this->isDraft($object));

		// Sort by date (newest first)
		usort($objects, function (array $a, array $b): int {
			$dateA = $a[$this->fieldMap['date']] ?? 0;
			$dateB = $b[$this->fieldMap['date']] ?? 0;

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

			$items[] = $this->item($object, $url);
		}

		return $this->writer->write($meta, $items, 'rss');
	}

	/**
	 * Whether an index row is a draft. Always the literal `draft` property —
	 * were the field mappable, `?draft=anything` would switch the filter off.
	 * A schema that has `draft` but does not index it is not covered: the feed
	 * reads index rows.
	 *
	 * @param array<string,mixed> $object
	 */
	private function isDraft(array $object): bool
	{
		return filter_var($object['draft'] ?? false, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * One object as a FeedWriter item, through the field map.
	 *
	 * @param array<string,mixed> $object
	 *
	 * @return array<string,mixed>
	 */
	private function item(array $object, string $url): array
	{
		$id    = (string)$object['id'];
		$title = $object[$this->fieldMap['title']] ?? '';
		$date  = $object[$this->fieldMap['date']] ?? null;

		return [
			'id'      => $id,
			// Laminas requires a title; the id is the fallback
			'title'   => is_string($title) && $title !== '' ? $title : $id,
			'link'    => $url,
			'date'    => $date ?? time(),
			'summary' => $object[$this->fieldMap['content']] ?? '',
			'author'  => $object[$this->fieldMap['author']] ?? '',
			'media'   => $object[$this->fieldMap['media']] ?? '',
		];
	}

	/**
	 * Feed-level meta from the request's query options, with the site's name
	 * and homepage as the defaults.
	 *
	 * @param array<string,string|false> $options
	 *
	 * @return array<string,mixed>
	 */
	private function meta(array $options): array
	{
		// URL decode string options that come from query parameters
		foreach (['name', 'description', 'link', 'image', 'language'] as $key) {
			if (isset($options[$key]) && $options[$key] !== false) {
				$options[$key] = urldecode($options[$key]);
			}
		}

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
			'self'        => (string)($options['rssurl'] ?? ''),
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
