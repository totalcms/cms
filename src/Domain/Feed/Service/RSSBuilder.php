<?php

namespace TotalCMS\Domain\Feed\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use FeedWriter\Item;
use FeedWriter\RSS2;

final class RSSBuilder
{
	const DEFAULT_FIELD_MAP = [
		'title'   => 'title',
		'content' => 'summary',
		'media'   => 'media',
		'author'  => 'author',
		'date'    => 'date',
		'draft'   => 'draft',
	];

	/** @var array<string,string> */
	private array $fieldMap = self::DEFAULT_FIELD_MAP;
	private RSS2 $feed;

	public function __construct(
		private IndexReader $indexReader,
		private CollectionFetcher $collectionFetcher,
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
		if (is_null($index)) {
			throw new \Exception('Index not found for collection: ' . $collection);
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \Exception('Collection not found: ' . $collection);
		}
		if (!str_starts_with($collectionData->url, 'http')) {
			throw new \Exception('Invalid URL for collection: ' . $collection);
		}

		$this->setupFeed($options);

		foreach ($index->objects as $object) {
			$draft = $object[$this->fieldMap['draft']] ?? false;
			if ($draft) continue;

			$item = $this->createItem($collectionData, $object);
            $this->feed->addItem($item);
		}
		return $this->feed->generateFeed();
	}

	/** @param array<string,mixed> $object */
	private function createItem(CollectionData $collection, array $object): Item
	{
		$id      = $object['id'];
		$url     = $this->objectUrl($collection, $object['id']);
		$title   = $object[$this->fieldMap['title']]   ?? false;
		$author  = $object[$this->fieldMap['author']]  ?? false;
		$content = $object[$this->fieldMap['content']] ?? false;
		$media   = $object[$this->fieldMap['media']]   ?? false;
		$date    = $object[$this->fieldMap['date']]    ?? time();
		$mime    = $this->mimeType($media);

		$item = $this->feed->createNewItem();
		$item->setLink($url);
		$item->setId($id);
		$item->setDate(date(DATE_RSS, $date));
		if ($title) $item->setTitle($title);
		if ($author) $item->setAuthor($author);
		if ($content) $item->setDescription($content);
		if ($media) $item->addEnclosure($media, 0, $mime);

		return $item;
	}

	/** @param array<string,string> $options */
	private function setupFeed(array $options): void
	{
		$options = array_merge([
			'home'    => false,
			'rss'     => false,
			'image'   => false,
			'title'   => false,
			'summary' => false,
		], $options);

		$this->feed->setDate(time());
		$this->feed->setChannelElement('generator', 'Total CMS');
        if ($options['home'])
			$this->feed->setLink($options['home']);
		if ($options['rss'])
			$this->feed->setSelfLink($options['rss']);
		if ($options['image'])
			$this->feed->setImage($options['image'], strval($options['title']), strval($options['home']));
        if ($options['title'])
			$this->feed->setTitle($options['title']);
        if ($options['summary'])
			$this->feed->setDescription($options['summary']);
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

	private function objectUrl(CollectionData $collectionData, string $id): string
	{
		if ($collectionData->prettyUrl) {
			return sprintf('%s%s', $collectionData->url, $id);
		}

		return sprintf('%s?id=%s', $collectionData->url, $id);
	}
}
