<?php

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\CollectionMetaTransformer;

final class CollectionSaveAction
{
	public function __construct(private JsonRenderer $renderer, private CollectionSaver $service)
	{
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();
		$collection = $this->service->saveCollection($data);
		return $this->renderer->jsonItem($response, $collection, new CollectionMetaTransformer());
	}
}
