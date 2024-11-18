<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectPatchPropertyAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectPatcher $objectPatcher,
	) {}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();
		$object = $this->objectPatcher->patchObjectProperty(
			$args['collection'],
			$args['id'],
			$args['property'],
			$data
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
