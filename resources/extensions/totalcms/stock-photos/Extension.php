<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\RemoteFileDownloader;

require_once __DIR__ . '/PhotoProvider.php';
require_once __DIR__ . '/PixabayProvider.php';
require_once __DIR__ . '/SearchAction.php';
require_once __DIR__ . '/DownloadAction.php';

/**
 * Search Pixabay from the object editor and drop photos on image and gallery
 * fields. The panel only hands the field a payload; core's photo drop
 * (javascript/totalform/photo-drop.js) does the upload, with alt and credit.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$key = trim((string)$context->setting('pixabayKey', ''));

		// No key, no panel: nothing to search with.
		if ($key === '') {
			return;
		}

		$safeSearch  = $context->setting('safeSearch', true) !== false;
		$orientation = (string)$context->setting('defaultOrientation', 'all');

		$context->addContainerDefinition(
			PhotoProvider::class,
			fn ($c) => new PixabayProvider($c->get(HttpClientInterface::class), $c->get(CacheManager::class), $key, $safeSearch),
		);
		$context->addContainerDefinition(
			SearchAction::class,
			fn ($c) => new SearchAction($c->get(PhotoProvider::class), $c->get(JsonRenderer::class), $orientation),
		);
		$context->addContainerDefinition(
			DownloadAction::class,
			fn ($c) => new DownloadAction($c->get(PhotoProvider::class), $c->get(RemoteFileDownloader::class)),
		);

		// Every dashboard user who can edit objects may use the panel, not
		// just super admins; Access Groups can still withhold the extension.
		$context->addAdminRoutes(function ($group): void {
			$group->get('/search', SearchAction::class, permission: 'any');
			$group->get('/download', DownloadAction::class, permission: 'any');
		});

		$context->addAdminAsset('css', 'stock-photos.css');
		$context->addAdminAsset('js', 'stock-photos.js');
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
