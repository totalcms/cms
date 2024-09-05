<?php

namespace TotalCMS\Action\Cache;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\TwigCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action.
 */
final class CacheDeleteAction
{
	public function __construct(
		private TwigCacheCleaner $twigCacheCleaner,
		private JsonRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$deleted = $this->twigCacheCleaner->deleteCache();

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
