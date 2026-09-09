<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * The site's publishing entity — one `Organization` node per document.
 *
 * WebSite and Article both reference this node by `@id`, so `applies()` and
 * `id()` are public: a cross-reference is only emitted when this provider
 * actually contributes the node it points at.
 */
final class OrganizationProvider implements JsonLdProvider
{
	/** The `@id` every cross-reference to the organization uses. */
	public static function id(SeoContext $ctx): string
	{
		return $ctx->settings->baseUrl . '/#organization';
	}

	/** An organization needs a name — the SEO setting, else the site name. */
	public static function applies(SeoContext $ctx): bool
	{
		return self::name($ctx) !== '';
	}

	/** @return list<array<string,mixed>> */
	public function nodes(SeoContext $ctx, MetaPayload $meta): array
	{
		if (!self::applies($ctx)) {
			return [];
		}

		$node = [
			'@type' => 'Organization',
			'@id'   => self::id($ctx),
			'name'  => self::name($ctx),
			'url'   => $ctx->settings->baseUrl,
		];

		if ($ctx->settings->organizationLogo !== '') {
			$node['logo'] = ['@type' => 'ImageObject', 'url' => $ctx->settings->organizationLogo];
		}
		if ($ctx->settings->sameAs !== []) {
			$node['sameAs'] = $ctx->settings->sameAs;
		}

		return [$node];
	}

	private static function name(SeoContext $ctx): string
	{
		return $ctx->settings->organizationName !== '' ? $ctx->settings->organizationName : $ctx->siteName;
	}
}
