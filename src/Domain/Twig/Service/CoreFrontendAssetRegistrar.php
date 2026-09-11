<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Data\FrontendAsset;

/**
 * Registers Total CMS core frontend assets into the Twig adapter so they
 * flow through {{ cms.assetsHead() }} / {{ cms.assetsBody() }} alongside
 * any extension-registered assets.
 *
 * These are the assets historically hardcoded into the Stacks template.
 * Centralizing them here means we can ship new core assets without asking
 * customers to update their templates.
 *
 * A site that uses none of a feature's markup can leave its files out with
 * `$settings['frontendAssets']['except']` (names as in ASSETS); the boot
 * step passes that list to register(). Extension assets are never affected.
 */
final class CoreFrontendAssetRegistrar extends CoreAssetRegistrar
{
	protected const ASSETS = [
		['name' => 'icons',      'path' => 'icons.css',      'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['name' => 'content',    'path' => 'content.css',    'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['name' => 'cms-grid',   'path' => 'cms-grid.css',   'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['name' => 'gallery',    'path' => 'gallery.css',    'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['name' => 'pagination', 'path' => 'pagination.css', 'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['name' => 'content',    'path' => 'content.js',     'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
		['name' => 'gallery',    'path' => 'gallery.js',     'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
		// htmx is the bundled UMD build — it must load as a classic script so
		// the top-level `window.htmx` side effect actually reaches `window`.
		// Loading it as `type="module"` puts the binding in module scope and
		// leaves the global undefined, breaking any code that calls htmx as a
		// global (e.g. `htmx.ajax(...)` in admin-table.js).
		['name' => 'htmx',       'path' => 'htmx.min.js',    'type' => 'js',  'position' => 'body', 'module' => false, 'preload' => true],
	];

	/**
	 * @param list<FrontendAsset> $records
	 */
	protected function addAssets(TotalCMSTwigAdapter $adapter, array $records): void
	{
		$adapter->addFrontendAssets($records);
	}
}
