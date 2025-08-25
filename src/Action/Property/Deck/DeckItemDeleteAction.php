<?php

namespace TotalCMS\Action\Property\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckItemRemover;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Deletes an item from a deck property.
 */
readonly class DeckItemDeleteAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DeckItemRemover $deckItemRemover,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$object = $this->deckItemRemover->removeDeckItem(
				$args['collection'],
				$args['id'],
				$args['property'],
				$args['itemId']
			);

			return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		}
	}
}
