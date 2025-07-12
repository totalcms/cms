<?php

namespace TotalCMS\Action\Playground;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Playground\Service\PlaygroundFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class PlaygroundFetchAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private PlaygroundFetcher $playgroundFetcher,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$object = $this->playgroundFetcher->getSnippet($args['id']);
		} catch (\UnexpectedValueException $e) {
			throw new HttpNotFoundException($request, $e->getMessage());
		}

		return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
	}
}
