<?php

namespace TotalCMS\Domain\Object\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\LocalizedtextData;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

readonly class ObjectExporter
{
	private LoggerInterface $logger;

	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
		private IndexFilter $indexFilter,
		private Config $config,
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
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
		[
			'headers'         => $headers,
			'cardSubProps'    => $cardSubProps,
			'localizedProps'  => $localizedProps,
		]            = $this->buildCsvHeaders($schema);
		$objects     = [$headers];
		$errors      = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id);
				$objects[] = $this->buildCsvRow($object, $headers, $cardSubProps, $localizedProps);
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
	 * Build CSV column headers for a schema. Card and localized-text properties
	 * expand into `{name}.{subKey}` columns; everything else uses the property
	 * name as a single column.
	 *
	 *   - Card properties pull sub-keys from the linked schemaref's fields.
	 *   - Localized properties pull sub-keys from the site's configured
	 *     `i18n.available` locales (`localizedtext`, `localizedtextarea`, and
	 *     `localizedstyledtext` all share the same `$ref`).
	 *
	 * Cards or localized properties with no resolvable sub-keys fall back to
	 * a single column (the standard CSV-stringified representation).
	 *
	 * @return array{headers: array<int,string>, cardSubProps: array<string,array<int,string>>, localizedProps: array<string,array<int,string>>}
	 */
	private function buildCsvHeaders(SchemaData $schema): array
	{
		$headers        = [];
		$cardSubProps   = [];
		$localizedProps = [];

		foreach ($schema->properties as $name => $property) {
			$nameStr = (string)$name;
			if (!is_array($property)) {
				$headers[] = $nameStr;
				continue;
			}

			$ref = $property['$ref'] ?? null;

			if ($ref === SchemaData::PROPERTY_TYPE_TO_REF['card']) {
				$subProps = $this->fetchCardSubProperties($property);
				if ($subProps === []) {
					$headers[] = $nameStr;
					continue;
				}
				$cardSubProps[$nameStr] = $subProps;
				foreach ($subProps as $subProp) {
					$headers[] = $nameStr . '.' . $subProp;
				}
				continue;
			}

			if ($ref === SchemaData::PROPERTY_TYPE_TO_REF['localizedtext']) {
				$locales = $this->fetchLocalizedSubKeys();
				if ($locales === []) {
					// Operator hasn't opted into i18n yet — fall back to one column.
					$headers[] = $nameStr;
					continue;
				}
				$localizedProps[$nameStr] = $locales;
				foreach ($locales as $locale) {
					$headers[] = $nameStr . '.' . $locale;
				}
				continue;
			}

			$headers[] = $nameStr;
		}

		return [
			'headers'        => $headers,
			'cardSubProps'   => $cardSubProps,
			'localizedProps' => $localizedProps,
		];
	}

	/**
	 * Return the sub-key list for a localized property: the codes from
	 * `$config->i18n['available']`, in configured order. Order matters so
	 * the CSV columns mirror the operator's preferred locale order
	 * (which is also the Twig helper's fall-down order).
	 *
	 * @return array<int,string>
	 */
	private function fetchLocalizedSubKeys(): array
	{
		$out = [];
		foreach ($this->config->i18n['available'] as $locale) {
			$code = (string)($locale['code'] ?? '');
			if ($code !== '') {
				$out[] = $code;
			}
		}

		return $out;
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
	 * Build a single CSV row, pulling sub-values directly from CardData /
	 * LocalizedtextData for dot-notation columns and falling back to the
	 * standard CSV-stringified representation for everything else.
	 *
	 * @param array<int,string>               $headers
	 * @param array<string,array<int,string>> $cardSubProps
	 * @param array<string,array<int,string>> $localizedProps
	 *
	 * @return array<int,string>
	 */
	private function buildCsvRow(ObjectData $object, array $headers, array $cardSubProps, array $localizedProps): array
	{
		$forCsv = $object->forCsv();
		$row    = [];

		foreach ($headers as $header) {
			if (str_contains($header, '.')) {
				[$propName, $subKey] = explode('.', $header, 2);
				if (isset($cardSubProps[$propName])) {
					$row[] = $this->extractCardSubValue($object, $propName, $subKey);
					continue;
				}
				if (isset($localizedProps[$propName])) {
					$row[] = $this->extractLocalizedSubValue($object, $propName, $subKey);
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
	 * Pull a single locale's value from a `LocalizedtextData` property.
	 * Missing locales produce an empty cell — the importer treats those as
	 * absent (won't overwrite existing data on re-import unless a value is
	 * supplied), matching CardData behaviour.
	 */
	private function extractLocalizedSubValue(ObjectData $object, string $propName, string $locale): string
	{
		$property = $object->properties->get($propName);
		if (!$property instanceof LocalizedtextData) {
			return '';
		}

		$value = $property->values[$locale] ?? '';

		// Escape newlines so the CSV stays single-line per row (mirrors
		// ObjectData::forCsv() and extractCardSubValue() above). Tiptap HTML
		// rarely contains literal newlines but a `<pre>` block could.
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
