<?php

namespace TotalCMS\Action\Property\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckItemUpdater;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Updates an existing item in a deck property.
 */
readonly class DeckItemUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DeckItemUpdater $deckItemUpdater,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		try {
			$object = $this->deckItemUpdater->updateDeckItem(
				$args['collection'],
				$args['id'],
				$args['property'],
				$args['itemId'],
				$data
			);

			return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		}
	}
}
