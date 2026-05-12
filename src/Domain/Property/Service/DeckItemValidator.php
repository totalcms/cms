<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaValidator;

/**
 * Service for validating deck items against their schema.
 */
readonly class DeckItemValidator
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private SchemaValidator $schemaValidator,
	) {
	}

	/**
	 * Validate a deck item against its schema.
	 *
	 * @param array<string,mixed> $itemData
	 *
	 * @throws \InvalidArgumentException if validation fails
	 */
	public function validate(string $collection, string $propertyName, array $itemData): void
	{
		// Get the deck schema ID from the collection schema
		$schema         = $this->schemaFetcher->fetchSchemaForCollection($collection);
		$propertyConfig = $schema->properties[$propertyName] ?? null;

		if ($propertyConfig === null) {
			return; // No property config, skip validation
		}

		$schemaref = PropertyDefinition::extractSchemaRef($propertyConfig);

		if ($schemaref === null) {
			return; // No schema reference, skip validation
		}

		$deckSchemaId = SchemaFetcher::extractSchemaId($schemaref);

		try {
			$this->schemaValidator->validateSchema($itemData, $deckSchemaId);
		} catch (\DomainException $e) {
			throw new \InvalidArgumentException($e->getMessage(), 0, $e);
		}
	}
}
