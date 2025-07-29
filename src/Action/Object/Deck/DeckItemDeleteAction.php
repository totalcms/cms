<?php

namespace TotalCMS\Action\Object\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Deletes an item from a deck property.
 */
final class DeckItemDeleteAction
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
			$object = $this->deckManager->deleteDeckItem(
				$args['collection'],
				$args['id'],
				$args['property'],
				$args['name']
			);

			return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer());
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		}
	}
}