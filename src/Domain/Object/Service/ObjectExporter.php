<?php

namespace TotalCMS\Domain\Object\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

readonly class ObjectExporter
{
	private LoggerInterface $logger;

	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
		private IndexFilter $indexFilter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('object-exporter');
	}

	/** @return array<array<string,mixed>> */
	public function exportAllObjects(string $collection): array
	{
		$objects   = [];
		$objectIds = $this->storage->fetchObjectIds($collection);

		foreach ($objectIds as $id) {
			$object    = $this->objectFetcher->fetchObject($collection, $id);
			$objects[] = $object->toArray();
		}

		return $objects;
	}

	/**
	 * Export all objects for JSON format with error tracking.
	 *
	 * @return array{data: array<array<string,mixed>>, errors: array<string>}
	 */
	public function exportAllObjectsForJson(string $collection): array
	{
		$objects   = [];
		$objectIds = $this->storage->fetchObjectIds($collection);
		$errors    = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id);
				$objects[] = $object->toArray();
			} catch (\Throwable $e) {
				// Log the error with details for debugging
				$this->logger->warning('Skipping object during JSON export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Export filtered objects for JSON format with error tracking.
	 *
	 * Uses IndexFilter to apply include/exclude criteria before exporting.
	 *
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{data: array<array<string,mixed>>, errors: array<string>}
	 */
	public function exportFilteredObjectsForJson(string $collection, array $options): array
	{
		$objectIds = $this->fetchFilteredObjectIds($collection, $options);
		$objects   = [];
		$errors    = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id);
				$objects[] = $object->toArray();
			} catch (\Throwable $e) {
				$this->logger->warning('Skipping object during JSON export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Export all objects for CSV format.
	 *
	 * @return array{data: array<array<int,string>>, errors: array<string>}
	 */
	public function exportAllObjectsForCSv(string $collection): array
	{
		return $this->buildCsvExport($collection, $this->storage->fetchObjectIds($collection));
	}

	/**
	 * Export filtered objects for CSV format.
	 *
	 * Uses IndexFilter to apply include/exclude criteria before exporting.
	 *
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{data: array<array<int,string>>, errors: array<string>}
	 */
	public function exportFilteredObjectsForCsv(string $collection, array $options): array
	{
		return $this->buildCsvExport($collection, $this->fetchFilteredObjectIds($collection, $options));
	}

	/**
	 * Build a CSV export for the given object IDs. Card properties are expanded
	 * into one column per sub-property using dot notation (e.g. mycard.title).
	 *
	 * @param array<string> $objectIds
	 *
	 * @return array{data: array<array<int,string>>, errors: array<string>}
	 */
	private function buildCsvExport(string $collection, array $objectIds): array
	{
		$schema                                                  = $this->schemaFetcher->fetchSchemaForCollection($collection);
		['headers' => $headers, 'cardSubProps' => $cardSubProps] = $this->buildCsvHeaders($schema);
		$objects                                                 = [$headers];
		$errors                                                  = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id);
				$objects[] = $this->buildCsvRow($object, $headers, $cardSubProps);
			} catch (\Throwable $e) {
				$this->logger->warning('Skipping object during CSV export due to data mismatch', [
					'collection' => $collection,
					'object_id'  => $id,
					'error'      => $e->getMessage(),
					'exception'  => $e::class,
					'hint'       => 'This usually happens when the schema was modified after objects were created. Check if the stored data type matches the current schema.',
				]);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Build CSV column headers for a schema. Card properties expand into
	 * `{cardName}.{subProperty}` columns; everything else uses the property name.
	 *
	 * @return array{headers: array<int,string>, cardSubProps: array<string,array<int,string>>}
	 */
	private function buildCsvHeaders(SchemaData $schema): array
	{
		$headers      = [];
		$cardSubProps = [];

		foreach ($schema->properties as $name => $property) {
			$nameStr = (string)$name;
			if (!is_array($property) || ($property['$ref'] ?? null) !== SchemaData::PROPERTY_TYPE_TO_REF['card']) {
				$headers[] = $nameStr;
				continue;
			}

			$subProps = $this->fetchCardSubProperties($property);
			if ($subProps === []) {
				// Sub-schema missing or unloadable — fall back to a single flat column.
				$headers[] = $nameStr;
				continue;
			}

			$cardSubProps[$nameStr] = $subProps;
			foreach ($subProps as $subProp) {
				$headers[] = $nameStr . '.' . $subProp;
			}
		}

		return ['headers' => $headers, 'cardSubProps' => $cardSubProps];
	}

	/**
	 * Fetch the sub-property names for a card property, excluding the `id` field
	 * (cards don't have a meaningful ID — see CardField::buildSubFields).
	 *
	 * @param array<string,mixed> $property
	 *
	 * @return array<int,string>
	 */
	private function fetchCardSubProperties(array $property): array
	{
		$schemaref = PropertyDefinition::extractSchemaRef($property);
		if ($schemaref === null) {
			return [];
		}

		try {
			$subSchema = $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($schemaref));
		} catch (\Throwable) {
			return [];
		}

		return array_values(array_filter(
			array_map(strval(...), array_keys($subSchema->properties)),
			fn (string $subName): bool => $subName !== 'id',
		));
	}

	/**
	 * Build a single CSV row, pulling card sub-values directly from CardData
	 * for dot-notation columns and falling back to the standard CSV-stringified
	 * representation for everything else.
	 *
	 * @param array<int,string>                  $headers
	 * @param array<string,array<int,string>>    $cardSubProps
	 *
	 * @return array<int,string>
	 */
	private function buildCsvRow(ObjectData $object, array $headers, array $cardSubProps): array
	{
		$forCsv = $object->forCsv();
		$row    = [];

		foreach ($headers as $header) {
			if (str_contains($header, '.')) {
				[$cardName, $subProp] = explode('.', $header, 2);
				if (isset($cardSubProps[$cardName])) {
					$row[] = $this->extractCardSubValue($object, $cardName, $subProp);
					continue;
				}
			}
			$row[] = $forCsv[$header] ?? '';
		}

		return $row;
	}

	private function extractCardSubValue(ObjectData $object, string $cardName, string $subProp): string
	{
		$property = $object->properties->get($cardName);
		if (!$property instanceof CardData) {
			return '';
		}

		$raw = $property->get($subProp);
		if ($raw === null) {
			return '';
		}

		// Nested arrays (e.g. an image stored inside a card) round-trip as JSON.
		$value = is_scalar($raw) ? (string)$raw : (string)json_encode($raw, JSON_UNESCAPED_SLASHES);

		// Match ObjectData::forCsv() — escape newlines so the CSV stays single-line per row.
		return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $value);
	}

	/**
	 * Get object IDs filtered by include/exclude criteria.
	 *
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array<string>
	 */
	private function fetchFilteredObjectIds(string $collection, array $options): array
	{
		$filteredObjects = $this->indexFilter->fetchFilteredIndex($collection, $options);

		return array_map(
			fn (array $object): string => (string)($object['id'] ?? ''),
			$filteredObjects
		);
	}
}
