<?php

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action to clear image cache for a specific collection.
 */
final readonly class CollectionImageCacheDeleteAction
{
	public function __construct(
		private ImageCacheService $imageCacheService,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data       = (array)$request->getParsedBody();
		$collection = $request->getAttribute('collection') ?? $data['collection'] ?? '';

		if (empty($collection)) {
			return $this->renderer->json($response->withStatus(400), [
				'error' => 'Collection parameter is required',
			]);
		}

		try {
			$statsBefore = $this->imageCacheService->getCollectionImageCacheStats($collection);
			$deleted     = $this->imageCacheService->clearCollectionImageCache($collection);
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response->withStatus(500), [
				'error' => 'Failed to clear collection image cache: ' . $e->getMessage(),
			]);
		}

		// Get stats after clearing to show what was removed
		$statsAfter = $this->imageCacheService->getCollectionImageCacheStats($collection);

		return $this->renderer->json($response, [
			'deleted'    => $deleted,
			'collection' => $collection,
			'stats'      => [
				'before'        => $statsBefore,
				'after'         => $statsAfter,
				'files_removed' => $statsBefore['cached_files'],
				'size_freed_mb' => $statsBefore['total_size_mb'],
			],
		]);
	}
}
