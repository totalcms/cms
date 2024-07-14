<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectDeletePropertyAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectSaver $service
	) {
		$this->renderer = $renderer;
		$this->service  = $service;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args The routing arguments
	 *
	 * @return ResponseInterface the response
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args
	): ResponseInterface {
		$object = $this->service->deleteObjectProperty($args['collection'], $args['id'], $args['property']);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
