<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class ObjectSaveAction
{
	private JsonRenderer $renderer;
	private ObjectSaver $service;

	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param ObjectSaver $service Object save service
	 */
	public function __construct(JsonRenderer $renderer, ObjectSaver $service)
	{
		$this->renderer = $renderer;
		$this->service  = $service;
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request
	 * @param ResponseInterface $response
	 * @param array<string,string> $args
	 *
	 * @return ResponseInterface
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data   = json_decode($request->getBody(), true);
		$object = $this->service->saveObject($args['collection'], $data);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
