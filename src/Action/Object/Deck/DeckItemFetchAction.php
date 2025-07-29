<?php

namespace TotalCMS\Action\Object\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Fetches a specific item from a deck property.
 */
final class DeckItemFetchAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DeckManager $deckManager,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		try {
			$item = $this->deckManager->getDeckItem(
				$args['collection'],
				$args['id'],
				$args['property'],
				$args['name']
			);

			if ($item === null) {
				return $this->renderer->json($response, ['error' => 'Deck item not found'], 404);
			}

			return $this->renderer->json($response, $item);
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		}
	}
}