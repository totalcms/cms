<?php

namespace TotalCMS\Action\Playground;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Playground\Service\PlaygroundLister;
use TotalCMS\Renderer\JsonRenderer;

final class PlaygroundListAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PlaygroundLister $playgroundLister,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		try {
			$snippets = $this->playgroundLister->listSnippets();
			return $this->renderer->json($response, ['snippets' => $snippets]);
		} catch (\Exception $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 500);
		}
	}
}