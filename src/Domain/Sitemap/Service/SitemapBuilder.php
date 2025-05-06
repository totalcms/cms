<?php

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Index\Service\IndexReader;
use \TotalCMS\Domain\Sitemap\Data\Sitemap;

final class SitemapBuilder
{
	public function __construct(
		private IndexReader $indexReader,
		private CollectionFetcher $collectionFetcher,
	) {
	}

	/** @param array<string,string> $options */
	public function buildSitemap(string $collection, array $options = []): string
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

		$sitemap = new Sitemap();

		$dateProperty = $options['date'] ?? 'date';
		unset($options['date']);

		foreach ($index->objects as $object) {
			$url = $this->objectUrl($collectionData, $object['id']);

			if (!empty($object[$dateProperty])) {
				$options['date'] = $object[$dateProperty];
			}

			$location = $sitemap->newLocation($url, $options);
			$sitemap->addLocation($location);
		}

		return $sitemap->toXML();
	}

	private function objectUrl(CollectionData $collectionData, string $id): string
	{
		if ($collectionData->prettyUrl) {
			return sprintf('%s%s', $collectionData->url, $id);
		}

		return sprintf('%s?id=%s', $collectionData->url, $id);
	}
}
