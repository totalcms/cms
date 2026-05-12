<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Exception;

/**
 * Thrown when a sitemap is requested for a collection whose sitemap card is
 * not enabled. The action layer translates this into a 404 response so the
 * existence of disabled-but-present collection sitemaps is not exposed.
 */
class SitemapDisabledException extends \Exception
{
}
