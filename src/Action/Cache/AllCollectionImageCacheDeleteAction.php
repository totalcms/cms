<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action to clear image cache for all collections.
 */
readonly class AllCollectionImageCacheDeleteAction
{
	public function __construct(
		private ImageCacheService $imageCacheService,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$result = $this->imageCacheService->clearAllCollectionImageCaches();
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response->withStatus(500), [
				'error' => 'Failed to clear all image caches: ' . $e->getMessage(),
			]);
		}

		return $this->renderer->json($response, [
			'deleted'                   => true,
			'collections_processed'     => $result['collections_processed'],
			'cache_directories_cleared' => $result['cache_directories_cleared'] ?? 0,
			'errors'                    => $result['errors'],
		]);
	}
}
