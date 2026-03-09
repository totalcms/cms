<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Report\Service;

use League\Csv\Writer;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LoggerFactory;

/**
 * Report exporter with field selection and deck expansion.
 *
 * Unlike ObjectExporter which dumps all data, this service exports
 * only user-selected fields and supports deck data expansion into
 * separate rows for CSV output.
 */
readonly class ReportExporter
{
	private LoggerInterface $logger;

	public function __construct(
		private IndexRepository $storage,
		private ObjectFetcher $objectFetcher,
		private IndexFilter $indexFilter,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('report-exporter');
	}

	/**
	 * Parse query params into fields list and filter options.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @throws \InvalidArgumentException if fields param is missing or empty
	 *
	 * @return array{fields: array<string>, options: array<string,string>}
	 */
	public function parseParams(array $params): array
	{
		// Backwards compatibility: remap 'filter' to 'include'
		if (isset($params['filter']) && !isset($params['include'])) {
			$params['include'] = $params['filter'];
		}

		$fieldsParam = $params['fields'] ?? '';
		if (is_array($fieldsParam)) {
			$fields = array_filter(array_map(trim(...), $fieldsParam));
		} elseif (is_string($fieldsParam) && $fieldsParam !== '') {
			$fields = array_filter(array_map(trim(...), explode(',', $fieldsParam)));
		} else {
			throw new \InvalidArgumentException('The "fields" parameter is required. Provide a comma-separated list of field names.');
		}

		if ($fields === []) {
			throw new \InvalidArgumentException('At least one field must be specified.');
		}

		$options = [];
		if (isset($params['include']) && is_string($params['include'])) {
			$options['include'] = $params['include'];
		}
		if (isset($params['exclude']) && is_string($params['exclude'])) {
			$options['exclude'] = $params['exclude'];
		}

		return [
			'fields'  => array_values($fields),
			'options' => $options,
		];
	}

	/**
	 * Export selected fields as a JSON-encodable array.
	 *
	 * Returns just the data array when there are no errors,
	 * or a wrapped structure with errors when some objects failed.
	 *
	 * @param array<string>        $fields  Property names to include (deck fields use dot notation: deck.field)
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array<mixed>
	 */
	public function exportJsonData(string $collection, array $fields, array $options = []): array
	{
		$result = $this->exportJson($collection, $fields, $options);

		if (count($result['errors']) > 0) {
			return [
				'data'   => $result['data'],
				'errors' => $result['errors'],
			];
		}

		return $result['data'];
	}

	/**
	 * Export selected fields as a CSV string.
	 *
	 * @param array<string>        $fields  Property names to include (deck fields use dot notation: deck.field)
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{csv: string, errors: array<string>}
	 */
	public function exportCsvString(string $collection, array $fields, array $options = []): array
	{
		$result = $this->exportCsv($collection, $fields, $options);

		$csv = Writer::fromString('');
		$csv->insertOne($result['headers']);
		$csv->insertAll($result['data']);

		return [
			'csv'    => $csv->toString(),
			'errors' => $result['errors'],
		];
	}

	/**
	 * Export selected fields as JSON.
	 *
	 * @param array<string>       $fields  Property names to include (deck fields use dot notation: deck.field)
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{data: array<array<string,mixed>>, errors: array<string>}
	 */
	public function exportJson(string $collection, array $fields, array $options = []): array
	{
		$objectIds  = $this->getObjectIds($collection, $options);
		$parsed     = $this->parseFields($fields);
		$objects    = [];
		$errors     = [];

		foreach ($objectIds as $id) {
			try {
				$object    = $this->objectFetcher->fetchObject($collection, $id)->toArray();
				$filtered  = $this->filterObjectFields($object, $parsed);
				$objects[] = $filtered;
			} catch (\Throwable $e) {
				$this->logError('JSON', $collection, $id, $e);
				$errors[] = $id;
			}
		}

		return [
			'data'   => $objects,
			'errors' => $errors,
		];
	}

	/**
	 * Export selected fields as CSV rows with deck expansion.
	 *
	 * Deck items are expanded into separate rows. When multiple decks are selected,
	 * each deck's items generate their own rows with the other deck columns left blank.
	 *
	 * @param array<string>       $fields  Property names to include (deck fields use dot notation: deck.field)
	 * @param array<string,string> $options Filter options (include, exclude)
	 *
	 * @return array{headers: array<string>, data: array<array<string>>, errors: array<string>}
	 */
	public function exportCsv(string $collection, array $fields, array $options = []): array
	{
		$objectIds  = $this->getObjectIds($collection, $options);
		$parsed     = $this->parseFields($fields);
		$headers    = $this->buildCsvHeaders($parsed);
		$rows       = [];
		$errors     = [];

		foreach ($objectIds as $id) {
			try {
				$object  = $this->objectFetcher->fetchObject($collection, $id)->toArray();
				$newRows = $this->buildCsvRows($object, $parsed, $headers);
				foreach ($newRows as $row) {
					$rows[] = $row;
				}
			} catch (\Throwable $e) {
				$this->logError('CSV', $collection, $id, $e);
				$errors[] = $id;
			}
		}

		return [
			'headers' => $headers,
			'data'    => $rows,
			'errors'  => $errors,
		];
	}

	/**
	 * Parse field list into scalar fields and deck field groups.
	 *
	 * @param array<string> $fields
	 *
	 * @return array{scalars: array<string>, decks: array<string,array<string>>}
	 */
	private function parseFields(array $fields): array
	{
		$scalars = [];
		$decks   = [];

		foreach ($fields as $field) {
			if (str_contains($field, '.')) {
				[$deckName, $subField] = explode('.', $field, 2);
				$decks[$deckName][]    = $subField;
			} else {
				$scalars[] = $field;
			}
		}

		return [
			'scalars' => $scalars,
			'decks'   => $decks,
		];
	}

	/**
	 * Filter an object to only include requested fields.
	 *
	 * For JSON output: scalars are included directly, decks stay nested
	 * but only include requested sub-fields.
	 *
	 * @param array<string,mixed> $object
	 * @param array{scalars: array<string>, decks: array<string,array<string>>} $parsed
	 *
	 * @return array<string,mixed>
	 */
	private function filterObjectFields(array $object, array $parsed): array
	{
		$result = [];

		foreach ($parsed['scalars'] as $field) {
			if (array_key_exists($field, $object)) {
				$result[$field] = $object[$field];
			}
		}

		foreach ($parsed['decks'] as $deckName => $subFields) {
			if (!isset($object[$deckName]) || !is_array($object[$deckName])) {
				continue;
			}

			$filteredDeck = [];
			foreach ($object[$deckName] as $itemId => $itemData) {
				if (!is_array($itemData)) {
					continue;
				}
				$filteredItem = [];
				foreach ($subFields as $subField) {
					if (array_key_exists($subField, $itemData)) {
						$filteredItem[$subField] = $itemData[$subField];
					}
				}
				$filteredDeck[$itemId] = $filteredItem;
			}
			$result[$deckName] = $filteredDeck;
		}

		return $result;
	}

	/**
	 * Build CSV column headers from parsed fields.
	 *
	 * @param array{scalars: array<string>, decks: array<string,array<string>>} $parsed
	 *
	 * @return array<string>
	 */
	private function buildCsvHeaders(array $parsed): array
	{
		$headers = $parsed['scalars'];

		foreach ($parsed['decks'] as $deckName => $subFields) {
			foreach ($subFields as $subField) {
				$headers[] = $deckName . '.' . $subField;
			}
		}

		return $headers;
	}

	/**
	 * Build CSV rows for a single object with deck expansion.
	 *
	 * @param array<string,mixed> $object
	 * @param array{scalars: array<string>, decks: array<string,array<string>>} $parsed
	 * @param array<string> $headers
	 *
	 * @return array<array<string>>
	 */
	private function buildCsvRows(array $object, array $parsed, array $headers): array
	{
		// Build the scalar values (shared across all rows)
		$scalarValues = [];
		foreach ($parsed['scalars'] as $field) {
			$scalarValues[$field] = $this->formatCsvValue($object[$field] ?? '');
		}

		// If no deck fields selected, return a single row
		if ($parsed['decks'] === []) {
			$row = [];
			foreach ($headers as $header) {
				$row[] = $scalarValues[$header] ?? '';
			}

			return [$row];
		}

		// Collect all deck item rows (additive, not cartesian)
		$deckRows = [];
		foreach ($parsed['decks'] as $deckName => $subFields) {
			$deckData = $object[$deckName] ?? [];
			if (!is_array($deckData) || $deckData === []) {
				continue;
			}

			foreach ($deckData as $itemData) {
				if (!is_array($itemData)) {
					continue;
				}

				$deckRow = [];
				foreach ($subFields as $subField) {
					$deckRow[$deckName . '.' . $subField] = $this->formatCsvValue($itemData[$subField] ?? '');
				}

				$deckRows[] = ['deck' => $deckName, 'values' => $deckRow];
			}
		}

		// If no deck data exists, still output one row with blank deck columns
		if ($deckRows === []) {
			$row = [];
			foreach ($headers as $header) {
				$row[] = $scalarValues[$header] ?? '';
			}

			return [$row];
		}

		// Build one row per deck item
		$rows = [];
		foreach ($deckRows as $deckRow) {
			$row = [];
			foreach ($headers as $header) {
				if (isset($scalarValues[$header])) {
					$row[] = $scalarValues[$header];
				} elseif (isset($deckRow['values'][$header])) {
					$row[] = $deckRow['values'][$header];
				} else {
					$row[] = '';
				}
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Format a value for CSV output.
	 */
	private function formatCsvValue(mixed $value): string
	{
		if (is_array($value)) {
			return (string)json_encode($value, JSON_UNESCAPED_SLASHES);
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		$str = (string)$value;

		return str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], $str);
	}

	/**
	 * Get object IDs, optionally filtered.
	 *
	 * @param array<string,string> $options
	 *
	 * @return array<string>
	 */
	private function getObjectIds(string $collection, array $options): array
	{
		$hasFilters = isset($options['include']) || isset($options['exclude']);

		if (!$hasFilters) {
			return $this->storage->fetchObjectIds($collection);
		}

		$filteredObjects = $this->indexFilter->fetchFilteredIndex($collection, $options);

		return array_map(
			fn (array $object): string => (string)($object['id'] ?? ''),
			$filteredObjects
		);
	}

	private function logError(string $format, string $collection, string $id, \Throwable $e): void
	{
		$this->logger->warning("Skipping object during {$format} report export due to data mismatch", [
			'collection' => $collection,
			'object_id'  => $id,
			'error'      => $e->getMessage(),
			'exception'  => $e::class,
		]);
	}
}
