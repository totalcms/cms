<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

/**
 * Imports JSON data into a deck property of an existing object.
 *
 * Accepts two formats:
 * - Dictionary: { "item_id": { ... }, "item_id2": { ... } }
 * - Array: [ { "id": "item_id", ... }, { "id": "item_id2", ... } ]
 */
class DeckJsonImporter
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectUpdater $objectUpdater,
		private readonly SchemaFetcher $schemaFetcher,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('deck-json-importer');
	}

	/**
	 * Import JSON data into a deck property.
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function import(
		string $collection,
		string $objectId,
		string $property,
		UploadedFileInterface $file,
		bool $update = false,
	): int {
		$this->logger->info("Starting deck JSON import: {$collection}/{$objectId}/{$property}");

		$object       = $this->objectFetcher->fetchObject($collection, $objectId);
		$deckProperty = $object->properties->get($property);

		if (!$deckProperty instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$property}' is not a deck property");
		}

		// Parse JSON
		$jsonContent = (string)$file->getStream();
		$data        = json_decode($jsonContent, true);

		if (!is_array($data)) {
			throw new \InvalidArgumentException('Invalid JSON: expected an array or object');
		}

		// Normalize to dictionary format
		$items = $this->normalizeToDictionary($data, $collection, $property);

		if ($items === []) {
			return 0;
		}

		$existingDeck = $deckProperty->deck;
		$importCount  = 0;

		foreach ($items as $itemId => $itemData) {
			$exists = isset($existingDeck[$itemId]);

			if ($exists && !$update) {
				$this->logger->info("Skipping existing deck item: {$itemId}");
				continue;
			}

			$itemData['id'] = $itemId;

			if ($exists) {
				$existingDeck[$itemId] = array_merge($existingDeck[$itemId], $itemData);
				$this->logger->info("Updated deck item: {$itemId}");
			} else {
				$existingDeck[$itemId] = $itemData;
				$this->logger->info("Imported deck item: {$itemId}");
			}

			$importCount++;
		}

		if ($importCount === 0) {
			return 0;
		}

		$objectData            = $object->toArray();
		$objectData[$property] = $existingDeck;

		$this->objectUpdater->updateObject($collection, $objectId, $objectData);

		$this->logger->info("Deck JSON import completed. Imported {$importCount} items");

		return $importCount;
	}

	/**
	 * Normalize input to a dictionary keyed by item ID.
	 * Accepts dictionary format or array-of-objects format.
	 *
	 * @param array<mixed> $data
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function normalizeToDictionary(array $data, string $collection, string $property): array
	{
		// Already a dictionary (associative array)
		if ($data !== [] && !array_is_list($data)) {
			$normalized = [];
			foreach ($data as $key => $item) {
				if (!is_array($item)) {
					continue;
				}
				$itemId = $this->sanitizeId((string)$key);
				if ($itemId !== '') {
					$item['id']            = $itemId;
					$normalized[$itemId]   = $item;
				}
			}

			return $normalized;
		}

		// Array of objects — extract ID from each item
		$autogenPattern = $this->getIdAutogenPattern($collection, $property);
		$normalized     = [];

		foreach ($data as $item) {
			if (!is_array($item)) {
				continue;
			}

			$itemId = '';
			if (isset($item['id']) && trim((string)$item['id']) !== '') {
				$itemId = $this->sanitizeId((string)$item['id']);
			} elseif ($autogenPattern !== '') {
				$raw    = \TotalCMS\Domain\Object\Service\AutogenService::generateWithOidCount($autogenPattern, $item, 0);
				$itemId = $this->sanitizeId(SlugData::slugify($raw));
			} else {
				$itemId = str_replace('-', '_', AutogenIdService::generateUid());
			}

			if ($itemId !== '') {
				$item['id']          = $itemId;
				$normalized[$itemId] = $item;
			}
		}

		return $normalized;
	}

	private function sanitizeId(string $id): string
	{
		return str_replace('-', '_', SlugData::slugify($id));
	}

	/**
	 * Get the autogen pattern for the deck schema's ID property.
	 */
	private function getIdAutogenPattern(string $collection, string $propertyName): string
	{
		try {
			$schema         = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$propertyConfig = $schema->properties[$propertyName] ?? null;
			if (!$propertyConfig) {
				return '';
			}

			$schemaref = PropertyDefinition::extractSchemaRef($propertyConfig);
			if ($schemaref === null) {
				return '';
			}

			$deckSchemaId = SchemaFetcher::extractSchemaId($schemaref);
			$deckSchema   = $this->schemaFetcher->fetchSchema($deckSchemaId);

			return $deckSchema->properties['id']['settings']['autogen'] ?? '';
		} catch (\Exception) {
			return '';
		}
	}
}
