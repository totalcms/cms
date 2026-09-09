<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * A two- or three-step `BreadcrumbList`: Home → (collection) → this page.
 *
 * The collection step only appears when the collection's URL is a real path we
 * can link to. Templated URLs (`/blog/{{ year }}/{{ id }}`) have no meaningful
 * "listing" URL to point at, so those collections are skipped rather than
 * linked to a half-rendered template.
 */
final class BreadcrumbProvider implements JsonLdProvider
{
	/** The `@id` WebPage's `breadcrumb` reference uses. */
	public static function id(SeoContext $ctx): string
	{
		return $ctx->url . '#breadcrumb';
	}

	/** A breadcrumb needs a page to end at. */
	public static function applies(SeoContext $ctx): bool
	{
		return $ctx->url !== '' && ($ctx->kind === 'page' || $ctx->kind === 'object');
	}

	/** @return list<array<string,mixed>> */
	public function nodes(SeoContext $ctx, MetaPayload $meta): array
	{
		if (!self::applies($ctx)) {
			return [];
		}

		$items = [$this->item(1, 'Home', $ctx->settings->baseUrl)];

		$collectionUrl = $this->collectionUrl($ctx);
		if ($collectionUrl !== '') {
			$items[] = $this->item(count($items) + 1, $this->collectionLabel($ctx), $collectionUrl);
		}

		$items[] = $this->item(count($items) + 1, $meta->rawTitle, $ctx->url);

		return [[
			'@type'           => 'BreadcrumbList',
			'@id'             => self::id($ctx),
			'itemListElement' => $items,
		]];
	}

	/** @return array<string,mixed> */
	private function item(int $position, string $name, string $url): array
	{
		return ['@type' => 'ListItem', 'position' => $position, 'name' => $name, 'item' => $url];
	}

	/**
	 * The absolute URL of the collection's listing page, or `''` when there
	 * isn't one. Providers stay pure, so this reads the collection's own URL
	 * rather than asking ObjectUrlBuilder: a `{` in the pattern (either the
	 * Slim `{id}` or the Twig `{{ id }}` form) means "per-object template",
	 * which is not a listing.
	 */
	private function collectionUrl(SeoContext $ctx): string
	{
		$collection = $ctx->collectionMeta;
		if ($ctx->kind !== 'object' || $collection === null) {
			return '';
		}

		$url = trim($collection->url);
		if ($url === '' || str_contains($url, '{')) {
			return '';
		}

		$url = CollectionData::prettyUrlBase($url);
		if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
			return $url;
		}

		return $ctx->settings->baseUrl . '/' . ltrim($url, '/');
	}

	private function collectionLabel(SeoContext $ctx): string
	{
		$collection = $ctx->collectionMeta;
		if ($collection === null) {
			return $ctx->collectionId;
		}
		if ($collection->labelPlural !== '') {
			return $collection->labelPlural;
		}

		return $collection->name !== '' ? $collection->name : ucfirst($collection->id);
	}
}
