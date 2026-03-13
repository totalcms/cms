<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\AutogenService;
use TotalCMS\Domain\Object\Service\CalcService;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Prepares deck item data before saving.
 * Handles ID generation, autogen fields, and calc fields for deck items.
 */
readonly class DeckItemFactory
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private AutogenIdService $autogenIdService,
		private AutogenService $autogenService,
		private CalcService $calcService,
	) {
	}

	/**
	 * Prepare deck item data by applying autogen and calc fields.
	 *
	 * @param array<string,mixed> $itemData
	 *
	 * @return array<string,mixed>
	 */
	public function prepareItemData(string $collection, string $propertyName, array $itemData): array
	{
		$itemData = $this->applyAutogenFields($collection, $propertyName, $itemData);

		return $this->applyCalcFields($collection, $propertyName, $itemData);
	}

	/**
	 * Generate an item ID using autogen settings from the deck schema.
	 *
	 * @param array<string,mixed> $itemData
	 */
	public function generateIdIfNeeded(string $collection, string $propertyName, array $itemData): string
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
			// If anything fails, return empty and let the caller handle it
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
					$itemData[$property] = $this->calcService->clampValue($result, $propertySchema['settings'] ?? []);
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
}
