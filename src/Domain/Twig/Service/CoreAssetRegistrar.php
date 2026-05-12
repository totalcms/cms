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
	 * The assets this registrar contributes, in render order.
	 *
	 * @var list<array{path: string, type: 'css'|'js', position: 'head'|'body', module: bool, preload: bool}>
	 */
	protected const ASSETS = [];

	public function register(TotalCMSTwigAdapter $adapter): void
	{
		$assetsDir = PathResolver::packageRoot() . '/public/assets';

		$records = [];
		foreach (static::ASSETS as $asset) {
			$mtime = @filemtime($assetsDir . '/' . $asset['path']);
			$query = $mtime !== false ? '?v=' . $mtime : '';

			$records[] = new FrontendAsset(
				type: $asset['type'],
				url: '/assets/' . $asset['path'] . $query,
				position: $asset['position'],
				module: $asset['module'],
				preload: $asset['preload'],
			);
		}
		$this->addAssets($adapter, $records);
	}

	/**
	 * @param list<FrontendAsset> $records
	 */
	abstract protected function addAssets(TotalCMSTwigAdapter $adapter, array $records): void;
}
