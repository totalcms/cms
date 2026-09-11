<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Support\PathResolver;

/**
 * Shared registration loop for the core asset registrars. Subclasses declare
 * their asset list in the ASSETS constant and implement addAssets() to push
 * the built records into the right adapter slot (frontend vs admin).
 *
 * Centralizing the loop here means cache-busting and URL-shape decisions
 * live in one place across both surfaces.
 */
abstract class CoreAssetRegistrar
{
	/**
	 * The assets this registrar contributes, in render order. `name` is the
	 * feature the file belongs to — a stylesheet and a script for the same
	 * feature share one name, so excluding `gallery` drops both files (and
	 * the script's preload hint), never one half of a pair. The exclusion
	 * itself happens at render time in TotalCMSTwigAdapter, from the site's
	 * `frontendAssets.except` setting and the options passed to the helpers.
	 *
	 * @var list<array{name: string, path: string, type: 'css'|'js', position: 'head'|'body', module: bool, preload: bool}>
	 */
	protected const ASSETS = [];

	public function register(TotalCMSTwigAdapter $adapter): void
	{
		$assetsDir = PathResolver::packageRoot() . '/public/assets';

		$records = [];
		foreach (static::ASSETS as $asset) {
			$assetPath = $assetsDir . '/' . $asset['path'];
			$mtime     = is_file($assetPath) ? filemtime($assetPath) : false;
			$query     = $mtime !== false ? '?v=' . $mtime : '';

			$records[] = new FrontendAsset(
				type: $asset['type'],
				url: '/assets/' . $asset['path'] . $query,
				position: $asset['position'],
				module: $asset['module'],
				preload: $asset['preload'],
				name: $asset['name'],
			);
		}
		$this->addAssets($adapter, $records);
	}

	/**
	 * @param list<FrontendAsset> $records
	 */
	abstract protected function addAssets(TotalCMSTwigAdapter $adapter, array $records): void;
}
