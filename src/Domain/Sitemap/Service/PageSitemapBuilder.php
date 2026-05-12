<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Sitemap\Data\Sitemap;
use TotalCMS\Support\Config;

readonly class PageSitemapBuilder
{
	public function __construct(
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
		private Config $config,
	) {
	}

	/** @param array<string,string> $options */
	public function buildSitemap(array $options = []): string
	{
		$sitemap = new Sitemap();

		if (!$this->builderConfig->pagesCollectionExists()) {
			return $sitemap->toXML();
		}

		$dateProperty = $options['date'] ?? 'updated';
		unset($options['date']);

		$index = $this->indexReader->fetchIndex($this->builderConfig->getPagesCollectionId());

		/** @var array<string,mixed> $object */
		foreach ($index->objects as $object) {
			$page = new PageData($object);

			if (!$page->isPublished() || !$page->sitemap || $page->route === '') {
				continue;
			}

			// Dynamic routes (e.g. /blog/{id}) cannot be enumerated from the route pattern alone.
			// Those pages are typically backed by a collection — use /sitemap/{collection} for them.
			if (str_contains($page->route, '{')) {
				continue;
			}

			$url = 'https://' . $this->config->domain . $page->route;

			$locOptions = $options;
			if (!empty($object[$dateProperty])) {
				$locOptions['date'] = (string)$object[$dateProperty];
			}
			if ($page->changeFrequency !== '') {
				$locOptions['frequency'] = $page->changeFrequency;
			}
			if ($page->priority > 0) {
				$locOptions['priority'] = (string)$page->priority;
			}

			$sitemap->addURL($url, $locOptions);
		}

		return $sitemap->toXML();
	}
}
