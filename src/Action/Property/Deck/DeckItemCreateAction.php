<?php

namespace TotalCMS\Action\Property\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
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
		private SchemaFetcher $schemaFetcher,
		private AutogenIdService $autogenIdService,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data   = (array)$request->getParsedBody();
		$itemId = $data['id'] ?? '';

		// If no ID provided, try to generate one using autogen
		if (empty($itemId)) {
			$itemId = $this->generateItemIdIfNeeded($args['collection'], $args['property'], $data);
		}

		if (empty($itemId)) {
			return $this->renderer->json($response, ['error' => 'Deck item id is required'])->withStatus(400);
		}

		// Update data with the generated ID
		$data['id'] = $itemId;

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

	/**
	 * Generate an item ID using autogen settings from the deck schema.
	 *
	 * @param array<string,mixed> $itemData
	 */
	private function generateItemIdIfNeeded(string $collection, string $propertyName, array $itemData): string
	{
		try {
			// Get the collection schema
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

			// Get the property config for this deck
			$propertyConfig = $schema->properties[$propertyName] ?? null;
			if (!$propertyConfig) {
				return '';
			}

			// Get deckref from property config
			$deckref = $propertyConfig['deckref'] ?? $propertyConfig['settings']['deckref'] ?? null;
			if (empty($deckref)) {
				return '';
			}

			// Get the deck schema
			$deckSchemaId = SchemaFetcher::extractSchemaId($deckref);
			$deckSchema   = $this->schemaFetcher->fetchSchema($deckSchemaId);

			// Check if ID field has autogen settings
			$idProperty     = $deckSchema->properties['id'] ?? [];
			$autogenPattern = $idProperty['settings']['autogen'] ?? null;

			if (!empty($autogenPattern)) {
				return $this->autogenIdService->generateId($autogenPattern, $collection, $itemData);
			}
		} catch (\Exception) {
			// If anything fails, return empty and let the error handler deal with it
		}

		return '';
	}
}
