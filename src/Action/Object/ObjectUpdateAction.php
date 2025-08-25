<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectUpdater $objectUpdater,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$object = $this->objectUpdater->updateObject($args['collection'], $args['id'], $data);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
