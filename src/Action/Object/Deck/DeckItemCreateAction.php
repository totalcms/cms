<?php

namespace TotalCMS\Action\Object\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Creates a new item in a deck property.
 */
final class DeckItemCreateAction
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
		$name = $data['name'] ?? '';

		if (empty($name)) {
			return $this->renderer->json($response, ['error' => 'Deck item name is required'], 400);
		}

		unset($data['name']); // Remove name from data since it's used as the key

		try {
			$object = $this->deckManager->createDeckItem(
				$args['collection'],
				$args['id'],
				$args['property'],
				$name,
				$data
			);

			return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer())->withStatus(201);
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()], 400);
		}
	}
}