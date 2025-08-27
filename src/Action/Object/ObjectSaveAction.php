<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectSaveAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param ObjectSaver $service Object save service
	 */
	public function __construct(private JsonRenderer $renderer, private ObjectSaver $service)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data   = (array)$request->getParsedBody();
		$object = $this->service->saveObject($args['collection'], $data);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
