<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Service\WatermarkCleanupService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action to clear the watermark cache.
 */
readonly class WatermarkCacheDeleteAction
{
	public function __construct(
		private WatermarkCleanupService $watermarkCleanupService,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$count = $this->watermarkCleanupService->clearOldCache(0);
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response->withStatus(500), [
				'error' => 'Failed to clear watermark cache: ' . $e->getMessage(),
			]);
		}

		return $this->renderer->json($response, [
			'deleted'       => true,
			'files_cleared' => $count,
		]);
	}
}
