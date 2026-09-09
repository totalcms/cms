<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * The `WebSite` node — always present, so every other node has something
 * site-wide to hang off.
 */
final class WebSiteProvider implements JsonLdProvider
{
	/** The `@id` every cross-reference to the website uses. */
	public static function id(SeoContext $ctx): string
	{
		return $ctx->settings->baseUrl . '/#website';
	}

	/** @return list<array<string,mixed>> */
	public function nodes(SeoContext $ctx, MetaPayload $meta): array
	{
		$node = [
			'@type' => 'WebSite',
			'@id'   => self::id($ctx),
			'name'  => $ctx->siteName,
			'url'   => $ctx->settings->baseUrl,
		];

		// Only point at an Organization the graph actually carries.
		if (OrganizationProvider::applies($ctx)) {
			$node['publisher'] = ['@id' => OrganizationProvider::id($ctx)];
		}

		return [$node];
	}
}
