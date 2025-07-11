<?php

namespace TotalCMS\Action\Playground;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Playground\Service\PlaygroundUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class PlaygroundUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PlaygroundUpdater $playgroundUpdater,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$object = $this->playgroundUpdater->updateSnippet($args['id'], $data);

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
