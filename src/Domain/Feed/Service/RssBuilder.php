<?php

namespace TotalCMS\Domain\Feed\Service;

use FeedWriter\Item;
use FeedWriter\RSS2;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Support\Config;

final class RssBuilder
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
		private readonly IndexReader $indexReader,
		private readonly CollectionFetcher $collectionFetcher,
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
		$index = $this->indexReader->fetchIndex($collection);

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \Exception('Collection not found: ' . $collection);
		}

		$this->setupFeed($options);

		$objects = $index->objects->sortBy($this->fieldMap['date'] ?? 'date', SORT_REGULAR, true);
		foreach ($objects as $object) {
			$draft = $object[$this->fieldMap['draft']] ?? false;
			if ($draft) {
				continue;
			}

			$item = $this->createItem($collectionData, $object);
			$this->feed->addItem($item);
		}

		return $this->feed->generateFeed();
	}

	/** @param array<string,mixed> $object */
	private function createItem(CollectionData $collection, array $object): Item
	{
		$id      = $object['id'];
		$title   = $object[$this->fieldMap['title']] ?? false;
		$author  = $object[$this->fieldMap['author']] ?? false;
		$content = $object[$this->fieldMap['content']] ?? false;
		$media   = $object[$this->fieldMap['media']] ?? false;
		$date    = $object[$this->fieldMap['date']] ?? time();
		$mime    = $this->mimeType($media);
		$url     = CollectionData::objectUrl($collection, $object['id']);

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

	/** @param array<string,string> $options */
	private function setupFeed(array $options): void
	{
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
		switch ($extension) {
			case 'mp3':
				return 'audio/mpeg';
			case 'ogg':
				return 'audio/ogg';
			case 'wav':
				return 'audio/wav';
			case 'mp4':
				return 'video/mp4';
			case 'webm':
				return 'video/webm';
		}

		return 'application/octet-stream';
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
