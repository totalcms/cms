<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Service;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;

/**
 * Answers, for ONE object, the question the sitemap builders answer for a
 * whole collection: is this something a crawler should be told about, and
 * at what absolute URL?
 *
 * The rules are the sitemap's own, applied to a single record — a
 * collection's sitemap must be enabled and its include/exclude filters must
 * admit the object; a Site Builder page must be published, opted into the
 * sitemap, and routed statically; either kind is dropped when its SEO card
 * says noindex or its URL has an empty segment. Anything that would not be
 * in the sitemap is not submitted anywhere else either, so a crawler is
 * never told two different things about one URL.
 *
 * Drafts are not a rule here, as they are not one in the sitemap: a
 * collection keeps drafts out with its sitemap `exclude` filter, and that
 * same filter is honoured. Builder pages carry `draft` natively.
 */
readonly class SitemapUrlResolver
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $objectUrlBuilder,
		private IndexFilter $indexFilter,
		private SeoSettingsLoader $seoSettings,
		private BuilderConfigService $builderConfig,
	) {
	}

	/**
	 * The absolute public URL a sitemap would list for this object, or null
	 * when the sitemap would leave it out.
	 *
	 * @param array<string,mixed> $object
	 */
	public function urlFor(string $collection, array $object): ?string
	{
		if ($collection === $this->builderConfig->getPagesCollectionId()) {
			return $this->pageUrl($object);
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData || empty($collectionData->sitemap['enabled'])) {
			return null;
		}

		if (!$this->indexFilter->matchesFilter($object, $this->filterOptions($collectionData))) {
			return null;
		}

		$url = $this->objectUrlBuilder->buildUrl($collectionData, $object);
		if ($url === '' || $this->objectUrlBuilder->hasEmptySegments($url)) {
			return null;
		}

		if (SeoFields::fromArray(is_array($object['seo'] ?? null) ? $object['seo'] : [])->noindex) {
			return null;
		}

		return $this->absolute($url);
	}

	/** @param array<string,mixed> $object */
	private function pageUrl(array $object): ?string
	{
		$page = new PageData($object);

		if (!$page->isPublished() || !$page->sitemap || $page->route === '' || str_contains($page->route, '{')) {
			return null;
		}

		if (SeoFields::fromArray($page->seo)->noindex) {
			return null;
		}

		return $this->absolute($page->route);
	}

	/**
	 * The sitemap card's saved include/exclude, in the shape IndexFilter
	 * reads — the same defaults SitemapBuilder applies to the whole list.
	 *
	 * @return array<string,string>
	 */
	private function filterOptions(CollectionData $collectionData): array
	{
		$options = [];
		foreach (['include', 'exclude'] as $key) {
			$value = (string)($collectionData->sitemap[$key] ?? '');
			if ($value !== '') {
				$options[$key] = $value;
			}
		}

		return $this->indexFilter->extractFilterOptions($options);
	}

	private function absolute(string $url): string
	{
		if (str_starts_with($url, 'http')) {
			return $url;
		}

		return $this->seoSettings->load()->baseUrl . '/' . ltrim($url, '/');
	}
}
