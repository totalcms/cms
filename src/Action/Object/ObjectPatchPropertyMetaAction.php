<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectPatchPropertyMetaAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectPatcher $objectPatcher,
	) {}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$query  = $request->getQueryParams();

		$object = $this->objectPatcher->patchObjectPropertyMeta(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['name'],
			$data,
			$query['path'] ?? null, // Optional path URL parameter
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
