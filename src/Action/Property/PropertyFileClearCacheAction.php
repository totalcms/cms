<?php

namespace TotalCMS\Action\Property;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;

final class PropertyFileClearCacheAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PropertyCacheCleaner $service,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$deleted = $this->service->deleteFileCache(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['file']
		);

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
