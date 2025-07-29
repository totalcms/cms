<?php

namespace TotalCMS\Action\Object\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Updates an existing item in a deck property.
 */
final class DeckItemUpdateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DeckManager $deckManager,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		try {
			$object = $this->deckManager->updateDeckItem(
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