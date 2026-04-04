<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use League\Csv\Reader;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Service\AutogenIdService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

/**
 * Imports CSV data into a deck property of an existing object.
 * Each CSV row becomes a deck item.
 */
class DeckCsvImporter
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectUpdater $objectUpdater,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly IndexBuilder $indexBuilder,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('deck-csv-importer');
	}

	/**
	 * Import CSV rows into a deck property.
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
		$this->logger->info("Starting deck CSV import: {$collection}/{$objectId}/{$property}");

		// Fetch object and validate property is a deck
		$object       = $this->objectFetcher->fetchObject($collection, $objectId);
		$deckProperty = $object->properties->get($property);

		if (!$deckProperty instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$property}' is not a deck property");
		}

		// Parse and clean CSV
		$csv = Reader::fromString((string)$file->getStream());
		$csv->setHeaderOffset(0);

		$records    = CsvImporter::cleanCsvData($csv);
		$totalRows  = count($records);
		$this->logger->info("Found {$totalRows} records to import");

		if ($totalRows === 0) {
			return 0;
		}

		// Resolve autogen pattern for ID generation
		$autogenPattern = $this->getIdAutogenPattern($collection, $property);
		$hasIdColumn    = isset($records[0]['id']);

		// Build new deck items from CSV
		$existingDeck = $deckProperty->deck;
		$importCount  = 0;

		foreach ($records as $offset => $record) {
			try {
				$itemId = $this->resolveItemId($record, $hasIdColumn, $autogenPattern);

				if ($itemId === '') {
					$this->logger->warning("Skipping row {$offset}: could not determine item ID");
					continue;
				}

				$exists = isset($existingDeck[$itemId]);

				if ($exists && !$update) {
					$this->logger->info("Skipping existing deck item: {$itemId}");
					continue;
				}

				// Ensure id is in the record
				$record['id'] = $itemId;

				if ($exists) {
					$existingDeck[$itemId] = array_merge($existingDeck[$itemId], $record);
					$this->logger->info("Updated deck item: {$itemId}");
				} else {
					$existingDeck[$itemId] = $record;
					$this->logger->info("Imported deck item: {$itemId}");
				}

				$importCount++;
			} catch (\Exception $e) {
				$this->logger->error("Error importing row {$offset}: {$e->getMessage()}");
			}
		}

		if ($importCount === 0) {
			return 0;
		}

		// Update the parent object with the complete deck in one operation
		$objectData            = $object->toArray();
		$objectData[$property] = $existingDeck;

		$this->objectUpdater->updateObject($collection, $objectId, $objectData);
		$this->indexBuilder->buildIndex($collection);

		$this->logger->info("Deck CSV import completed. Imported {$importCount} of {$totalRows} items");

		return $importCount;
	}

	/**
	 * Resolve the item ID for a CSV row.
	 *
	 * @param array<string,mixed> $record
	 */
	private function resolveItemId(array $record, bool $hasIdColumn, string $autogenPattern): string
	{
		if ($hasIdColumn && trim((string)($record['id'] ?? '')) !== '') {
			$id = SlugData::slugify((string)$record['id']);

			return str_replace('-', '_', $id);
		}

		if ($autogenPattern !== '') {
			$raw = \TotalCMS\Domain\Object\Service\AutogenService::generateWithOidCount($autogenPattern, $record, 0);
			$id  = SlugData::slugify($raw);

			return str_replace('-', '_', $id);
		}

		// Fallback: generate a uid
		return str_replace('-', '_', AutogenIdService::generateUid());
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

			$deckref = $propertyConfig['deckref'] ?? $propertyConfig['settings']['deckref'] ?? null;
			if (empty($deckref)) {
				return '';
			}

			$deckSchemaId = SchemaFetcher::extractSchemaId((string)$deckref);
			$deckSchema   = $this->schemaFetcher->fetchSchema($deckSchemaId);

			return $deckSchema->properties['id']['settings']['autogen'] ?? '';
		} catch (\Exception) {
			return '';
		}
	}
}
