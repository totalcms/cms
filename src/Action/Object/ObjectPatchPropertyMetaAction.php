<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectPatchPropertyMetaAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectSaver $service,
	) {}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data   = (array)$request->getParsedBody();
		$object = $this->service->patchObjectPropertyMeta(
			$args['collection'],
			$args['id'],
			$args['property'],
			$args['name'],
			$data
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
