<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * The `WebPage` node for the document being rendered — the hub the breadcrumb
 * and any Article hang off.
 */
final class WebPageProvider implements JsonLdProvider
{
	/** The `@id` every cross-reference to this page uses. */
	public static function id(SeoContext $ctx): string
	{
		return $ctx->url . '#webpage';
	}

	/** There is no page node without a resolvable URL. */
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

		$node = [
			'@type'    => 'WebPage',
			'@id'      => self::id($ctx),
			'url'      => $ctx->url,
			'name'     => $meta->rawTitle,
			'isPartOf' => ['@id' => WebSiteProvider::id($ctx)],
		];

		if ($meta->description !== '') {
			$node['description'] = $meta->description;
		}
		if ($meta->ogImage !== '') {
			$node['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $meta->ogImage];
		}
		if (BreadcrumbProvider::applies($ctx)) {
			$node['breadcrumb'] = ['@id' => BreadcrumbProvider::id($ctx)];
		}

		if ($ctx->kind === 'object' && $this->str($ctx, 'created') !== '') {
			$node['datePublished'] = $this->str($ctx, 'created');
		}
		if ($ctx->kind === 'object' && $this->str($ctx, 'updated') !== '') {
			$node['dateModified'] = $this->str($ctx, 'updated');
		}

		return [$node];
	}

	private function str(SeoContext $ctx, string $key): string
	{
		$value = $ctx->object[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
