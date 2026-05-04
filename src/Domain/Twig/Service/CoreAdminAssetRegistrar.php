<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Data\FrontendAsset;

/**
 * Registers Total CMS core admin assets into the Twig adapter so they
 * flow through {{ cms.adminAssetsHead() }} / {{ cms.adminAssetsBody() }}
 * alongside any extension-registered admin assets.
 *
 * Centralizing them here means we can ship new core admin assets without
 * editing admin-dashboard.twig, and benefit from the same mtime-based
 * cache busting as everything else in the asset pipeline.
 *
 * Module scripts in body with preload hints (modulepreload in head)
 * mirror the previous admin-dashboard.twig hardcoded layout but as
 * deferred modules — htmx loads first so admin-table.js can call
 * window.htmx.* in subsequent modules.
 */
final class CoreAdminAssetRegistrar extends CoreAssetRegistrar
{
	protected const ASSETS = [
		['path' => 'content-bundled.css', 'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'icons.css',           'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'admin.css',           'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'dashboard.css',       'type' => 'css', 'position' => 'head', 'module' => false, 'preload' => false],
		['path' => 'htmx.min.js',         'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
		['path' => 'content.js',          'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
		['path' => 'admin.js',            'type' => 'js',  'position' => 'body', 'module' => true,  'preload' => true],
	];

	/**
	 * @param list<FrontendAsset> $records
	 */
	protected function addAssets(TotalCMSTwigAdapter $adapter, array $records): void
	{
		$adapter->addAdminAssets($records);
	}
}
