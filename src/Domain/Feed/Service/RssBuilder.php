<?php

namespace TotalCMS\Domain\Feed\Service;

use Laminas\Feed\Writer\Entry;
use Laminas\Feed\Writer\Feed;
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
	private readonly Feed $feed;

	public function __construct(
		private readonly IndexFilter $indexFilter,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly ObjectUrlBuilder $objectUrlBuilder,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly Config $config,
	) {
		$this->feed = new Feed();
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

			$entry = $this->createEntry($object, $url);
			$this->feed->addEntry($entry);
		}

		return $this->feed->export('rss');
	}

	/** @param array<string,mixed> $object */
	private function createEntry(array $object, string $url): Entry
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

		$entry = $this->feed->createEntry();
		$entry->setLink($url);
		$entry->setId($id);
		$entry->setDateModified(strtotime($date));

		if ($title) {
			$entry->setTitle($title);
		} else {
			// Laminas requires a title, use ID as fallback
			$entry->setTitle($id);
		}

		if ($author) {
			$entry->addAuthor(['name' => $author]);
		}

		if ($content) {
			$entry->setDescription($content);
		}

		if ($media) {
			$entry->setEnclosure([
				'uri'    => $media,
				'type'   => $mime,
				'length' => 0,
			]);
		}

		return $entry;
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

		$defaultLink = $this->homepage();
		// Ensure we have a valid link (Laminas requires valid URI)
		if (empty($defaultLink) || $defaultLink === 'http://') {
			$defaultLink = 'https://' . ($this->config->domain ?: 'localhost');
		}

		$options = array_merge([
			'link'        => $defaultLink,
			'rssurl'      => false,
			'image'       => false,
			'name'        => ($this->domainName() ?: 'RSS') . ' Feed',
			'description' => false,
			'language'    => false,
		], $options);

		$this->feed->setDateModified(time());
		$this->feed->setGenerator('Total CMS');
		$this->feed->setLink(strval($options['link']));

		if ($options['language']) {
			$this->feed->setLanguage($options['language']);
		}

		if ($options['rssurl']) {
			$this->feed->setFeedLink($options['rssurl'], 'rss');
		}

		if ($options['image']) {
			$this->feed->setImage([
				'uri'   => $options['image'],
				'title' => strval($options['name']),
				'link'  => strval($options['link']),
			]);
		}

		if ($options['name']) {
			$this->feed->setTitle($options['name']);
		}

		if ($options['description']) {
			$this->feed->setDescription($options['description']);
		} else {
			// Laminas requires a description
			$this->feed->setDescription(strval($options['name']));
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
