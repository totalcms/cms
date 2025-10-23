<?php

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Sitemap\Data\Sitemap;
use TotalCMS\Support\Config;

readonly class SitemapBuilder
{
	public function __construct(
		private IndexFilter $indexFilter,
		private CollectionFetcher $collectionFetcher,
		private Config $config,
	) {
	}

	/** @param array<string,string> $options */
	public function buildSitemap(string $collection, array $options = []): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \Exception('Collection not found: ' . $collection);
		}

		$dateProperty = $options['date'] ?? 'updated';
		unset($options['date']);

		// Fetch and filter the index
		$objects = $this->indexFilter->fetchFilteredIndex($collection, $options);

		$sitemap = new Sitemap();

		foreach ($objects as $object) {
			$url = CollectionData::objectUrl($collectionData, $object['id']);

			if (!str_starts_with($url, 'http')) {
				$url = 'https://' . $this->config->domain . $url;
			}

			if (!empty($object[$dateProperty])) {
				$options['date'] = $object[$dateProperty];
			}

			$location = $sitemap->newLocation($url, $options);
			$sitemap->addLocation($location);
		}

		return $sitemap->toXML();
	}
}
