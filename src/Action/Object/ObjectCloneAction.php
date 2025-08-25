<?php

namespace TotalCMS\Action\Object;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

readonly class ObjectCloneAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param ObjectCloner $service Object service
	 */
	public function __construct(private JsonRenderer $renderer, private ObjectCloner $service)
	{
	}

	/**
	 * Action.
	 *
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		$from = [
			'id'         => $args['id'],
			'collection' => $args['collection'],
		];
		$to = [
			'id'         => $data['id'] ?? $from['id'],
			'collection' => $data['collection'] ?? $from['collection'],
		];

		$object = $this->service->cloneObject($from, $to);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
