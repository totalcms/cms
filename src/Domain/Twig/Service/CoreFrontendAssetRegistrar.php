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
 */
final class CoreFrontendAssetRegistrar extends CoreAssetRegistrar
{
	protected const ASSETS = [
		['path' => 'icons.css',      'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'content.css',    'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'cms-grid.css',   'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'gallery.css',    'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'pagination.css', 'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'content.js',     'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
		['path' => 'gallery.js',     'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
		// htmx is the bundled UMD build — it must load as a classic script so
		// the top-level `window.htmx` side effect actually reaches `window`.
		// Loading it as `type="module"` puts the binding in module scope and
		// leaves the global undefined, breaking any code that calls htmx as a
		// global (e.g. `htmx.ajax(...)` in admin-table.js).
		['path' => 'htmx.min.js',    'type' => 'js',  'position' => 'body', 'module' => false, 'preload' => true],
	];

	/**
	 * @param list<FrontendAsset> $records
	 */
	protected function addAssets(TotalCMSTwigAdapter $adapter, array $records): void
	{
		$adapter->addFrontendAssets($records);
	}
}
