<?php

namespace TotalCMS\Action\Playground;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Playground\Service\PlaygroundSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final readonly class PlaygroundSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PlaygroundSaver $playgroundSaver,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data    = (array)$request->getParsedBody();
		$snippet = $this->playgroundSaver->saveSnippet($data);

		return $this->renderer->jsonItem($response, $snippet, new ObjectMetaTransformer());
	}
}
