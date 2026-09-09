<?php

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;
use TotalCMS\Domain\Sitemap\Data\Sitemap;
use TotalCMS\Domain\Sitemap\Exception\SitemapDisabledException;

readonly class SitemapBuilder
{
	public function __construct(
		private IndexFilter $indexFilter,
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $objectUrlBuilder,
		private SeoSettingsLoader $seoSettings,
	) {
	}

	/**
	 * Build a sitemap XML for a collection.
	 *
	 * Reads the saved sitemap card settings from the collection meta as defaults
	 * (date, frequency, priority, include, exclude). Query-string options passed
	 * in via `$options` override the saved defaults — preserves backwards
	 * compatibility with the existing Sitemap Generator admin tool.
	 *
	 * Throws SitemapDisabledException when the collection's sitemap is not
	 * enabled. The action layer translates that into a 404.
	 *
	 * @param array<string,string> $options
	 *
	 * @throws \DomainException If the collection does not exist
	 * @throws SitemapDisabledException If the collection's sitemap is disabled
	 */
	public function buildSitemap(string $collection, array $options = []): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \DomainException('Collection not found: ' . $collection);
		}

		$sitemapSettings = $collectionData->sitemap;
		if (empty($sitemapSettings['enabled'])) {
			throw new SitemapDisabledException(sprintf('Sitemap is not enabled for collection: %s', $collection));
		}

		// Saved settings act as defaults; query-string options override them.
		$options = array_merge($this->savedDefaults($sitemapSettings), $options);

		$dateProperty = $options['date'] ?? 'updated';
		unset($options['date']);

		// One base URL for the whole sitemap — the same Site SEO value the
		// canonical tags use, so a `www.` (or apex) choice is made in one place.
		$baseUrl = $this->seoSettings->load()->baseUrl;

		// Fetch and filter the index
		$objects = $this->indexFilter->fetchFilteredIndex($collection, $options);

		$sitemap = new Sitemap();

		foreach ($objects as $object) {
			$url = $this->objectUrlBuilder->buildUrl($collectionData, $object);

			// Skip objects with broken URLs (empty segments from missing template data)
			if ($url === '' || $this->objectUrlBuilder->hasEmptySegments($url)) {
				continue;
			}

			// Noindex pages are removed from sitemaps so crawlers don't get contradictory signals.
			if (SeoFields::fromArray(is_array($object['seo'] ?? null) ? $object['seo'] : [])->noindex) {
				continue;
			}

			if (!str_starts_with($url, 'http')) {
				$url = $baseUrl . '/' . ltrim($url, '/');
			}

			if (!empty($object[$dateProperty])) {
				$options['date'] = $object[$dateProperty];
			}

			$location = $sitemap->newLocation($url, $options);
			$sitemap->addLocation($location);
		}

		return $sitemap->toXML();
	}

	/**
	 * Reduce the saved sitemap card settings to only the keys the builder honors,
	 * skipping empty/zero values so they don't pollute every sitemap entry.
	 *
	 * @param array<string,mixed> $sitemapSettings
	 *
	 * @return array<string,string>
	 */
	private function savedDefaults(array $sitemapSettings): array
	{
		$defaults = [];

		foreach (['date', 'frequency', 'include', 'exclude'] as $key) {
			$value = $sitemapSettings[$key] ?? '';
			if ($value !== '') {
				$defaults[$key] = (string)$value;
			}
		}

		// Priority is a float; only emit when explicitly > 0
		$priority = (float)($sitemapSettings['priority'] ?? 0);
		if ($priority > 0) {
			$defaults['priority'] = (string)$priority;
		}

		return $defaults;
	}
}
