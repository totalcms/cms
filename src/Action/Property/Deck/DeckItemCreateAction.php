<?php

namespace TotalCMS\Action\Property\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Property\Service\DeckItemFactory;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Creates a new item in a deck property.
 */
readonly class DeckItemCreateAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DeckItemSaver $deckItemSaver,
		private DeckItemFactory $deckItemFactory,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$itemId = $data['id'] ?? '';

		// If no ID provided, try to generate one using autogen
		if (empty($itemId)) {
			$itemId = $this->deckItemFactory->generateIdIfNeeded($args['collection'], $args['property'], $data);
		}

		if (empty($itemId)) {
			return $this->renderer->json($response, ['error' => 'Deck item id is required'])->withStatus(400);
		}

		// Update data with the generated ID and prepare item data
		$data['id'] = $itemId;
		$data       = $this->deckItemFactory->prepareItemData($args['collection'], $args['property'], $data);

		try {
			$object = $this->deckItemSaver->saveDeckItem(
				$args['collection'],
				$args['id'],
				$args['property'],
				$itemId,
				$data
			);

			return $this->renderer->jsonItem($response, $object, new ObjectMetaTransformer())->withStatus(201);
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, ['error' => $e->getMessage()])->withStatus(400);
		}
	}
}
