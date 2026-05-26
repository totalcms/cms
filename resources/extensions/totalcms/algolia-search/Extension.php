<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\AlgoliaSearch;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

// Bundled extensions don't ship their own composer autoloader. ExtensionManager
// only require_once's the entrypoint, so any sibling files the extension
// uses must be loaded explicitly here.
require_once __DIR__ . '/Service/AlgoliaSearchProvider.php';

/**
 * Algolia Search — bundled reference SearchProvider for T3 Phase 5.
 *
 * Implements T3's SearchProvider interface against Algolia. When enabled
 * + the site is on Pro edition + Algolia credentials are set, MCP search
 * tools (and future REST / site-wide search consumers) route through
 * Algolia's hybrid keyword + neural search.
 *
 * Pro-edition gated: register() silently skips when EditionFeature::
 * ALGOLIA_SEARCH is not enabled. The admin Extensions page still shows
 * this extension; it just doesn't register the provider until the
 * site is on Pro.
 *
 * Settings (Algolia App ID + admin/search API keys + index name) live
 * in per-extension storage via T3's standard ExtensionSettingsManager;
 * the schema is declared in settings.json alongside this file.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// Pro-edition gate: silently skip if not enabled. The Extensions
		// admin page still shows this extension (the extension itself is
		// always discoverable); it just won't register the provider until
		// the site has the Pro feature flag.
		$editionFeatures = $context->get(EditionFeatureService::class);
		if (!$editionFeatures->can(EditionFeature::ALGOLIA_SEARCH)) {
			return;
		}

		// Read persisted settings. Empty strings on fresh installs; the
		// provider's isAvailable() returns false in that case, triggering
		// the SearchService text fallback.
		$appId        = (string)$context->setting('appId', '');
		$adminApiKey  = (string)$context->setting('adminApiKey', '');
		$searchApiKey = (string)$context->setting('searchApiKey', '');
		$indexName    = (string)$context->setting('indexName', 'tcms_content');

		$context->registerSearchProvider(new Service\AlgoliaSearchProvider(
			$appId,
			$adminApiKey,
			$searchApiKey,
			$indexName,
		));
	}

	public function boot(ExtensionContext $context): void
	{
		// Nothing to do at boot — registration in register() is enough.
	}
}
