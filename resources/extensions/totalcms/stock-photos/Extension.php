<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\StockPhotos;

use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\RemoteFileDownloader;

require_once __DIR__ . '/PhotoProvider.php';
require_once __DIR__ . '/PixabayProvider.php';
require_once __DIR__ . '/GalleryProvider.php';
require_once __DIR__ . '/PhotoSources.php';
require_once __DIR__ . '/SourcesAction.php';
require_once __DIR__ . '/SearchAction.php';
require_once __DIR__ . '/DownloadAction.php';
require_once __DIR__ . '/LibraryAction.php';

/**
 * Search Pixabay and the site's own library galleries from the object editor,
 * and drop photos on image and gallery fields. The panel only hands the field
 * a payload; core's photo drop (javascript/totalform/photo-drop.js) does the
 * upload, with the photo's alt, credit and other details.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$key       = trim((string)$context->setting('pixabayKey', ''));
		$galleries = self::galleryIds($context->setting('galleries', []));

		// No key and no gallery, no panel: nothing to search.
		if ($key === '' && $galleries === []) {
			return;
		}

		$safeSearch  = $context->setting('safeSearch', true) !== false;
		$orientation = (string)$context->setting('defaultOrientation', 'all');

		$context->addContainerDefinition(
			PhotoSources::class,
			function ($c) use ($key, $safeSearch, $galleries): PhotoSources {
				$config    = $c->get(Config::class);
				$providers = [];
				// The site's own photos come first: the panel opens on the first tab.
				foreach ($galleries as $galleryId) {
					$providers[] = new GalleryProvider($galleryId, $c->get(PropertyFetcher::class), $config->api);
				}
				if ($key !== '') {
					$providers[] = new PixabayProvider($c->get(HttpClientInterface::class), $c->get(CacheManager::class), $key, $safeSearch);
				}

				return new PhotoSources($providers, $config, $c->get(AccessManager::class), $c->get(AccessControlService::class));
			},
		);
		$context->addContainerDefinition(
			SourcesAction::class,
			fn ($c) => new SourcesAction($c->get(PhotoSources::class), $c->get(JsonRenderer::class), $orientation),
		);
		$context->addContainerDefinition(
			SearchAction::class,
			fn ($c) => new SearchAction($c->get(PhotoSources::class), $c->get(JsonRenderer::class), $orientation),
		);
		$context->addContainerDefinition(
			DownloadAction::class,
			fn ($c) => new DownloadAction($c->get(PhotoSources::class), $c->get(RemoteFileDownloader::class)),
		);
		$context->addContainerDefinition(
			LibraryAction::class,
			fn ($c) => new LibraryAction($c->get(PhotoSources::class), $c->get(Config::class)),
		);

		// Every dashboard user who can edit objects may use the panel, not
		// just super admins; Access Groups can still withhold the extension.
		$context->addAdminRoutes(function ($group): void {
			$group->get('/sources', SourcesAction::class, permission: 'any');
			$group->get('/search', SearchAction::class, permission: 'any');
			$group->get('/download', DownloadAction::class, permission: 'any');
			$group->get('/library', LibraryAction::class, permission: 'any');
		});

		$context->addAdminAsset('css', 'stock-photos.css');
		$context->addAdminAsset('js', 'stock-photos.js');
	}

	public function boot(ExtensionContext $context): void
	{
	}

	/**
	 * The gallery ids chosen in the settings, in order, without blanks or repeats.
	 *
	 * @return list<string>
	 */
	public static function galleryIds(mixed $setting): array
	{
		// A list field saves an array; older or hand-edited settings may hold
		// JSON text or a comma-separated string.
		if (is_string($setting) && str_starts_with(trim($setting), '[')) {
			$setting = json_decode($setting, true) ?? [];
		}
		$ids = is_array($setting) ? $setting : explode(',', (string)$setting);
		$ids = array_map(fn ($id): string => trim((string)$id), $ids);

		return array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== '')));
	}
}
