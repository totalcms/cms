<?php

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
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
		private ObjectFetcher $objectFetcher,
		private SitemapMedia $media,
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
		$settings = $this->seoSettings->load();
		$baseUrl  = $settings->baseUrl;

		// The image and video properties to list. The index rarely holds them,
		// so naming any means reading each object in full.
		$images = self::propertyList($sitemapSettings['images'] ?? null);
		$videos = self::propertyList($sitemapSettings['videos'] ?? null);

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

			// Per object: an object without a date must not inherit the last one's
			$locationOptions = $options;
			if (!empty($object[$dateProperty]) && is_string($object[$dateProperty])) {
				$locationOptions['date'] = $object[$dateProperty];
			}

			$location = $sitemap->newLocation($url, $locationOptions);
			if ($images !== [] || $videos !== []) {
				$this->media->add($location, $this->fullObject($collection, $object), $collection, $images, $videos, $settings);
			}
			$sitemap->addLocation($location);
		}

		return $sitemap->toXML();
	}

	/**
	 * The object as stored, for its media properties; the index entry when
	 * the file cannot be read, so one bad object costs only its images.
	 *
	 * @param array<string,mixed> $entry
	 *
	 * @return array<string,mixed>
	 */
	private function fullObject(string $collection, array $entry): array
	{
		try {
			return $this->objectFetcher->fetchObject($collection, (string)($entry['id'] ?? ''))->toArray();
		} catch (\Throwable) {
			return $entry;
		}
	}

	/**
	 * A list setting as property names.
	 *
	 * @return list<string>
	 */
	private static function propertyList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_unique(array_filter(array_map(trim(...), array_filter($value, is_string(...))), static fn (string $v): bool => $v !== '')));
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
