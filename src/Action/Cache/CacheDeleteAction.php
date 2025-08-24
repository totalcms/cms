<?php

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action.
 */
final readonly class CacheDeleteAction
{
	public function __construct(
		private CacheManager $cacheManager,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$deleted = $this->cacheManager->clearAllCaches();

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
