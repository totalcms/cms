<?php

declare(strict_types=1);

use TotalCMS\Action\Admin\Utils\AccessPageData;
use TotalCMS\Action\Admin\Utils\ImportPageData;
use TotalCMS\Action\Admin\Utils\JumpStartPageData;
use TotalCMS\Action\Admin\Utils\OAuthPageData;
use TotalCMS\Action\Admin\Utils\SyncPageData;
use TotalCMS\Action\Admin\Utils\TwigDebuggerPageData;
use TotalCMS\Action\Admin\Utils\UpdatePageData;
use TotalCMS\Action\Admin\Utils\UtilsPageDataResolver;
use TotalCMS\Action\Admin\Utils\VisualizerPageData;

/**
 * Locks the page-name -> builder wiring registered by the
 * UtilsPageDataResolver factory in config/container.php.
 *
 * That map is the ONLY source of truth for which builder owns which utility
 * page — AdminUtilsAction just calls `$this->pageData->for($page)` and
 * merges whatever comes back (or nothing, for null) into utils.twig's
 * variables. Twig's non-strict variable access means a page silently
 * dropped from the map (or pointed at the wrong builder) renders a blank
 * page instead of failing loudly, so this test exists to catch that class
 * of mistake directly against the container wiring rather than by asserting
 * on rendered HTML.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	$this->setUpApp(bootstrap());
});

test('the container maps each page name to its expected builder', function (string $page, string $expectedClass): void {
	$resolver = $this->app->getContainer()->get(UtilsPageDataResolver::class);

	expect($resolver->for($page))->toBeInstanceOf($expectedClass);
})->with([
	'oauth-clients'         => ['oauth-clients', OAuthPageData::class],
	'oauth-grants'          => ['oauth-grants', OAuthPageData::class],
	'access-groups'         => ['access-groups', AccessPageData::class],
	'api-keys'              => ['api-keys', AccessPageData::class],
	'project-setup'         => ['project-setup', ImportPageData::class],
	'import-totalcms-one'   => ['import-totalcms-one', ImportPageData::class],
	'import-rss'            => ['import-rss', ImportPageData::class],
	'update'                => ['update', UpdatePageData::class],
	'sync'                  => ['sync', SyncPageData::class],
	'jumpstart'             => ['jumpstart', JumpStartPageData::class],
	'twig-debugger'         => ['twig-debugger', TwigDebuggerPageData::class],
	'collection-visualizer' => ['collection-visualizer', VisualizerPageData::class],
	'object-visualizer'     => ['object-visualizer', VisualizerPageData::class],
	'permission-matrix'     => ['permission-matrix', VisualizerPageData::class],
]);

test('unmapped pages resolve to no builder', function (string $page): void {
	$resolver = $this->app->getContainer()->get(UtilsPageDataResolver::class);

	expect($resolver->for($page))->toBeNull();
})->with([
	'twig-playground' => ['twig-playground'],
	'logs'            => ['logs'],
	'cache-manager'   => ['cache-manager'],
	'index'           => ['index'],
]);
