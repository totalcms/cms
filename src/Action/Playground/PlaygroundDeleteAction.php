<?php

namespace TotalCMS\Action\Playground;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Playground\Service\PlaygroundRemover;
use TotalCMS\Renderer\JsonRenderer;

final readonly class PlaygroundDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PlaygroundRemover $playgroundRemover,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$deleted = $this->playgroundRemover->deleteSnippet($args['id']);

		if ($deleted === false) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, ['deleted' => $deleted]);
	}
}
