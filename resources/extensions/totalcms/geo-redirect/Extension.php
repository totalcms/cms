<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\GeoRedirect;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

// Bundled extensions don't ship their own composer autoloader. ExtensionManager
// only require_once's the entrypoint, so any sibling files the extension
// uses must be loaded explicitly here.
require_once __DIR__ . '/GeoRedirectMiddleware.php';

/**
 * Geo Redirect — bundled with Total CMS.
 *
 * Registers the `geo-redirect` page-feature, which 302s visitors based on
 * their country. The country comes from CDN-injected request headers
 * (Cloudflare, Vercel, generic). Per-page config maps country codes to
 * target URLs via the page's data field.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// The middleware has no service deps — it's a pure stateless
		// reader of request headers and page data. Container definition
		// is just `new`.
		$context->addContainerDefinition(
			GeoRedirectMiddleware::class,
			fn () => new GeoRedirectMiddleware(),
		);

		$context->addPageMiddleware('geo-redirect', GeoRedirectMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
		// Nothing to do at boot — the middleware registration in register()
		// is enough.
	}
}
