<?php

namespace TotalCMS\Action\Property;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;

class PropertyClearCacheAction
{
	public function __construct(private readonly JsonRenderer $renderer, private readonly PropertyCacheCleaner $service)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$deleted = $this->service->deletePropertyCache($args['collection'], $args['id'], $args['property']);

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
