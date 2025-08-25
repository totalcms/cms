<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectDeletePropertyAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectRemover $objectRemover,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$object = $this->objectRemover->deleteObjectProperty(
			$args['collection'],
			$args['id'],
			$args['property']
		);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
