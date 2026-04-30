<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Sitemap\Data\SitemapIndex;
use TotalCMS\Support\Config;

/**
 * Builds a sitemap index that lists every sitemap available on the site.
 *
 * Includes:
 *   - The pages sitemap (`/sitemap/-pages`) when builder pages exist
 *   - One entry per collection where `sitemap.enabled === true`
 *
 * Disabled collections are silently omitted — they don't get an entry in the
 * index and `/sitemap/{collection}` returns 404 for them. This keeps disabled
 * collections fully out of the public surface.
 */
readonly class SitemapIndexBuilder
{
	public function __construct(
		private CollectionLister $collectionLister,
		private BuilderConfigService $builderConfig,
		private Config $config,
	) {
	}

	public function buildIndex(): string
	{
		$index = new SitemapIndex();
		$base  = $this->baseUrl();

		// Pages sitemap — included whenever the builder pages collection exists.
		// Routed at `/sitemap/-pages` so it never collides with a user collection named "pages".
		if ($this->builderConfig->pagesCollectionExists()) {
			$index->addSitemap($base . '/sitemap/-pages');
		}

		// One entry per collection that's been opted in via the sitemap card
		foreach ($this->collectionLister->listAllCollections() as $collection) {
			if (empty($collection->sitemap['enabled'])) {
				continue;
			}
			$index->addSitemap($base . '/sitemap/' . $collection->id);
		}

		return $index->toXML();
	}

	private function baseUrl(): string
	{
		$domain = $this->config->domain;
		if ($domain === '') {
			return '';
		}

		return 'https://' . $domain;
	}
}
