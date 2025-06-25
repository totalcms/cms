<?php

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action to clear image cache for a specific collection.
 */
final class CollectionImageCacheDeleteAction
{
	public function __construct(
		private CacheManager $cacheManager,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data       = (array)$request->getParsedBody();
		$collection = $request->getAttribute('collection') ?? $data['collection'] ?? '';

		if (empty($collection)) {
			return $this->renderer->json($response->withStatus(400), [
				'error' => 'Collection parameter is required'
			]);
		}

		// Get cache stats before clearing
		$statsBefore = $this->cacheManager->getCollectionImageCacheStats($collection);

		if (!$statsBefore['exists']) {
			return $this->renderer->json($response->withStatus(404), [
				'error' => 'Collection not found'
			]);
		}

		$deleted = $this->cacheManager->clearCollectionImageCache($collection);

		if ($deleted === false) {
			return $this->renderer->json($response->withStatus(500), [
				'error' => 'Failed to clear collection image cache'
			]);
		}

		// Get stats after clearing to show what was removed
		$statsAfter = $this->cacheManager->getCollectionImageCacheStats($collection);

		return $this->renderer->json($response, [
			'deleted' => $deleted,
			'collection' => $collection,
			'stats' => [
				'before' => $statsBefore,
				'after' => $statsAfter,
				'files_removed' => $statsBefore['cached_files'],
				'size_freed_mb' => $statsBefore['total_size_mb'],
			]
		]);
	}
}