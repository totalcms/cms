<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service\JsonLd;

use TotalCMS\Domain\Seo\Data\MetaPayload;
use TotalCMS\Domain\Seo\Data\SeoContext;

/**
 * One contributor to the JSON-LD `@graph`.
 *
 * Providers are pure: they take the already-resolved context and meta payload
 * and return zero or more schema.org nodes. They never inject services, touch
 * storage or reach for the request — everything they need has been resolved
 * onto the SeoContext by SeoContextFactory.
 */
interface JsonLdProvider
{
	/**
	 * The nodes this provider contributes, or `[]` when it does not apply.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function nodes(SeoContext $ctx, MetaPayload $meta): array;
}
