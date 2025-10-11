<?php

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Sitemap\Data\Sitemap;
use TotalCMS\Support\Config;

readonly class SitemapBuilder
{
	public function __construct(
		private IndexReader $indexReader,
		private CollectionFetcher $collectionFetcher,
		private Config $config,
	) {
	}

	/** @param array<string,string> $options */
	public function buildSitemap(string $collection, array $options = []): string
	{
		$index = $this->indexReader->fetchIndex($collection);

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (is_null($collectionData)) {
			throw new \Exception('Collection not found: ' . $collection);
		}

		$sitemap = new Sitemap();

		$dateProperty = $options['date'] ?? 'updated';
		unset($options['date']);

		// Extract filter options
		$filterOptions = $this->extractFilterOptions($options);

		foreach ($index->objects as $object) {
			// Skip objects that don't match the filter criteria
			if (!$this->matchesFilter($object, $filterOptions)) {
				continue;
			}

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

	/**
	 * Extract and remove filter options from the main options array.
	 *
	 * @param array<string,string> $options
	 *
	 * @return array<string,mixed>
	 */
	private function extractFilterOptions(array &$options): array
	{
		$filterOptions = [];

		// Extract filter parameters and remove them from the main options
		$filterKeys = ['include', 'exclude'];

		foreach ($filterKeys as $key) {
			if (isset($options[$key])) {
				$filterOptions[$key] = $options[$key];
				unset($options[$key]);
			}
		}

		return $filterOptions;
	}

	/**
	 * Check if an object matches the filter criteria.
	 *
	 * @param array<string,mixed> $object
	 * @param array<string,mixed> $filterOptions
	 */
	private function matchesFilter(array $object, array $filterOptions): bool
	{
		// If no filters are specified, include all objects
		if ($filterOptions === []) {
			return true;
		}

		// Check exclude filters first (early return if excluded)
		if (isset($filterOptions['exclude']) && $this->isExcluded($object, $filterOptions['exclude'])) {
			return false;
		}

		// Check include filters
		return !(isset($filterOptions['include']) && !$this->isIncluded($object, $filterOptions['include']));
	}

	/**
	 * Check if an object should be excluded based on exclude filters.
	 *
	 * @param array<string,mixed> $object
	 */
	private function isExcluded(array $object, string $excludeString): bool
	{
		$excludeFields = explode(',', $excludeString);

		foreach ($excludeFields as $excludeField) {
			$parts = explode(':', trim($excludeField), 2);
			$field = trim($parts[0]);
			$value = count($parts) === 2 ? trim($parts[1]) : 'true'; // Default to 'true' if no value provided

			// Convert 'true'/'false' strings to boolean for comparison
			if ($value === 'true') {
				$value = true;
			} elseif ($value === 'false') {
				$value = false;
			}

			if (isset($object[$field]) && $object[$field] === $value) {
				return true; // Object matches exclusion criteria
			}
		}

		return false; // Object doesn't match any exclusion criteria
	}

	/**
	 * Check if an object should be included based on filter criteria.
	 *
	 * @param array<string,mixed> $object
	 */
	private function isIncluded(array $object, string $filterString): bool
	{
		$includeFields = explode(',', $filterString);

		foreach ($includeFields as $includeField) {
			$parts = explode(':', trim($includeField), 2);
			$field = trim($parts[0]);
			$value = count($parts) === 2 ? trim($parts[1]) : 'true'; // Default to 'true' if no value provided

			// Convert 'true'/'false' strings to boolean for comparison
			if ($value === 'true') {
				$value = true;
			} elseif ($value === 'false') {
				$value = false;
			}

			if (!isset($object[$field]) || $object[$field] !== $value) {
				return false; // Object doesn't match this include criteria
			}
		}

		return true; // Object matches all include criteria
	}
}
