<?php

namespace TotalCMS\Action\Property;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;

readonly class PropertyFileClearCacheAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PropertyCacheCleaner $service,
		private PropertyRepository $storage,
	) {
	}

	/**
	 * Clear cache at `/{collection}/{id}/{property}/{path:.+}/cache`.
	 *
	 * The `path` segment is one of two things and the URL doesn't tell us which:
	 *   1. A filename inside a gallery property — clear `prop/.cache/{name}/`.
	 *   2. A child key inside a card-nested property — clear `prop/{key}/.cache/`.
	 *
	 * Dispatch on filesystem state: if `prop/{path}/` exists as a directory, it's
	 * a nested property; otherwise treat `path` as a filename.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$rawPath = $args['path'] ?? $args['name'] ?? '';
		$path    = PathUtils::sanitizeSubpath($rawPath);

		if ($path !== '' && $this->storage->directoryExists($args['collection'], $args['id'], $args['property'], $path)) {
			// Card-nested property cache — the child key resolves to a real dir.
			$deleted = $this->service->deletePropertyCache(
				$args['collection'],
				$args['id'],
				$args['property'],
				$path,
			);
		} else {
			// Gallery file cache — single image variants in the property's `.cache/`.
			$deleted = $this->service->deleteFileCache(
				$args['collection'],
				$args['id'],
				$args['property'],
				$path,
			);
		}

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
