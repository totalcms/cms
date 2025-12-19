<?php

namespace TotalCMS\Domain\Feed\Service;

use FeedWriter\Item;
use FeedWriter\RSS2;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

class RssBuilder
{
	public const DEFAULT_FIELD_MAP = [
		'title'   => 'title',
		'content' => 'summary',
		'media'   => 'media',
		'author'  => 'author',
		'date'    => 'updated',
		'draft'   => 'draft',
	];

	/** @var array<string,string> */
	private array $fieldMap = self::DEFAULT_FIELD_MAP;
	private readonly RSS2 $feed;

	public function __construct(
		private readonly IndexFilter $indexFilter,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectUrlBuilder $objectUrlBuilder,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly Config $config,
	) {
		$this->feed = new RSS2();
	}

	/** @param array<string,string> $fieldMap */
	public function setFieldMap(array $fieldMap): void
	{
		$this->fieldMap = array_merge(self::DEFAULT_FIELD_MAP, $fieldMap);
	}

	/** @param array<string,string> $options */
	public function buildFeed(string $collection, array $options = []): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \Exception('Collection not found: ' . $collection);
		}

		// Auto-filter drafts for blog schemas if no include/exclude filters are defined
		if (!isset($options['include']) && !isset($options['exclude'])) {
			$schemaData = $this->schemaFetcher->fetchSchema($collectionData->schema);
			if (in_array($schemaData->id, ['blog', 'blog-legacy'], true)) {
				$options['exclude'] = 'draft:true';
			}
		}

		// Extract limit (default: 25, 0 or -1 means no limit)
		$limit = isset($options['limit']) ? (int)$options['limit'] : 25;
		unset($options['limit']);

		$this->setupFeed($options);

		// Fetch and filter objects
		$objects = $this->indexFilter->fetchFilteredIndex($collection, $options);

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

		foreach ($objects as $object) {
			$url = $this->objectUrlBuilder->buildUrl($collectionData, $object);

			// Skip objects with broken URLs (empty segments from missing template data)
			if ($url === '' || $this->objectUrlBuilder->hasEmptySegments($url)) {
				continue;
			}

			$item = $this->createItem($collectionData, $object, $url);
			$this->feed->addItem($item);
		}

		return $this->feed->generateFeed();
	}

	/** @param array<string,mixed> $object */
	private function createItem(CollectionData $collection, array $object, string $url): Item
	{
		$id      = $object['id'];
		$title   = $object[$this->fieldMap['title']] ?? false;
		$author  = $object[$this->fieldMap['author']] ?? false;
		$content = $object[$this->fieldMap['content']] ?? false;
		$media   = $object[$this->fieldMap['media']] ?? false;
		$date    = $object[$this->fieldMap['date']] ?? time();
		$mime    = $this->mimeType($media);

		if (!str_starts_with($url, 'http')) {
			$url = 'https://' . $this->config->domain . $url;
		}

		$item = $this->feed->createNewItem();
		$item->setLink($url);
		$item->setId($id, true);
		$item->setDate(date(DATE_RSS, strtotime($date)));
		if ($title) {
			$item->setTitle($title);
		}
		if ($author) {
			$item->setAuthor($author);
		}
		if ($content) {
			$item->setDescription($content);
		}
		if ($media) {
			$item->addEnclosure($media, 0, $mime);
		}

		return $item;
	}

	/** @param array<string,string|false> $options */
	private function setupFeed(array $options): void
	{
		// URL decode string options that come from query parameters
		foreach (['name', 'description', 'link', 'image', 'language'] as $key) {
			if (isset($options[$key]) && $options[$key] !== false) {
				$options[$key] = urldecode($options[$key]);
			}
		}

		$options = array_merge([
			'link'        => $this->homepage(),
			'rssurl'      => false,
			'image'       => false,
			'name'        => $this->domainName() . ' Feed',
			'description' => false,
			'language'    => false,
		], $options);

		$this->feed->setDate(time());
		$this->feed->setChannelElement('generator', 'Total CMS');
		$this->feed->setLink(strval($options['link']));
		if ($options['language']) {
			$this->feed->setChannelElement('language', $options['language']);
		}
		if ($options['rssurl']) {
			$this->feed->setSelfLink($options['rssurl']);
		}
		if ($options['image']) {
			$this->feed->setImage($options['image'], strval($options['name']), strval($options['link']));
		}
		if ($options['name']) {
			$this->feed->setTitle($options['name']);
		}
		if ($options['description']) {
			$this->feed->setDescription($options['description']);
		}
	}

	private function mimeType(string $media): string
	{
		$extension = pathinfo($media, PATHINFO_EXTENSION);

		return match ($extension) {
			'mp3'   => 'audio/mpeg',
			'ogg'   => 'audio/ogg',
			'wav'   => 'audio/wav',
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			default => 'application/octet-stream',
		};
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
