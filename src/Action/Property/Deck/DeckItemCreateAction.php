<?php

namespace TotalCMS\Action\Property\Deck;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\AutogenService;
use TotalCMS\Domain\Object\Service\CalcService;
use TotalCMS\Domain\Property\Service\DeckItemSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
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
		private AutogenService $autogenService,
		private CalcService $calcService,
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

		// Apply autogen for non-ID fields in the deck item
		$data = $this->applyAutogenFields($args['collection'], $args['property'], $data);

		// Apply calc fields (computed values from other fields)
		$data = $this->applyCalcFields($args['collection'], $args['property'], $data);

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
			$deckSchema = $this->fetchDeckSchema($collection, $propertyName);
			if (!$deckSchema instanceof SchemaData) {
				return '';
			}

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

	/**
	 * Apply autogen patterns to non-ID fields in the deck item.
	 *
	 * @param array<string,mixed> $itemData
	 *
	 * @return array<string,mixed>
	 */
	private function applyAutogenFields(string $collection, string $propertyName, array $itemData): array
	{
		try {
			$deckSchema = $this->fetchDeckSchema($collection, $propertyName);
			if (!$deckSchema instanceof SchemaData) {
				return $itemData;
			}

			foreach ($deckSchema->properties as $property => $propertySchema) {
				if ($property === 'id') {
					continue;
				}

				$autogenPattern = $propertySchema['settings']['autogen'] ?? null;
				if (empty($autogenPattern)) {
					continue;
				}

				if (!empty($itemData[$property])) {
					continue;
				}

				$itemData[$property] = $this->autogenService->generate($autogenPattern, $collection, $itemData);
			}
		} catch (\Exception) {
			// Non-critical: return data as-is if schema lookup fails
		}

		return $itemData;
	}

	/**
	 * Apply calc expressions to computed fields in the deck item.
	 *
	 * @param array<string,mixed> $itemData
	 *
	 * @return array<string,mixed>
	 */
	private function applyCalcFields(string $collection, string $propertyName, array $itemData): array
	{
		try {
			$deckSchema = $this->fetchDeckSchema($collection, $propertyName);
			if (!$deckSchema instanceof SchemaData) {
				return $itemData;
			}

			foreach ($deckSchema->properties as $property => $propertySchema) {
				$calcExpression = $propertySchema['settings']['calc'] ?? null;
				if (empty($calcExpression)) {
					continue;
				}

				try {
					$result              = $this->calcService->evaluate($calcExpression, $itemData);
					$itemData[$property] = self::clampValue($result, $propertySchema['settings'] ?? []);
				} catch (\RuntimeException) {
					// Leave value as-is if calc expression fails
				}
			}
		} catch (\Exception) {
			// Non-critical: return data as-is if schema lookup fails
		}

		return $itemData;
	}

	/**
	 * Fetch the deck schema for a given collection property.
	 */
	private function fetchDeckSchema(string $collection, string $propertyName): ?SchemaData
	{
		$schema         = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$propertyConfig = $schema->properties[$propertyName] ?? null;
		if (!$propertyConfig) {
			return null;
		}

		$deckref = $propertyConfig['deckref'] ?? $propertyConfig['settings']['deckref'] ?? null;
		if (empty($deckref)) {
			return null;
		}

		$deckSchemaId = SchemaFetcher::extractSchemaId($deckref);

		return $this->schemaFetcher->fetchSchema($deckSchemaId);
	}

	/**
	 * Clamp a calc result to min/max settings if defined.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function clampValue(float $value, array $settings): float
	{
		$min = $settings['min'] ?? null;
		$max = $settings['max'] ?? null;

		if (is_numeric($min) && $value < (float)$min) {
			$value = (float)$min;
		}

		if (is_numeric($max) && $value > (float)$max) {
			$value = (float)$max;
		}

		return $value;
	}
}
