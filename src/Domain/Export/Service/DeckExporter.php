<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Export\Service;

use League\Csv\Writer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Exports deck property data in CSV or JSON format.
 */
readonly class DeckExporter
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
	) {
	}

	/**
	 * Get the transformed deck items for a property.
	 *
	 * @return array<string|int,array<string,mixed>>
	 */
	public function fetchDeckItems(string $collection, string $objectId, string $property): array
	{
		$object       = $this->objectFetcher->fetchObject($collection, $objectId);
		$deckProperty = $object->properties->get($property);

		if (!$deckProperty instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$property}' is not a deck property");
		}

		$objectData = $object->toArray();

		return $objectData[$property] ?? [];
	}

	/**
	 * Export deck items as a JSON string.
	 *
	 * @param array<string|int,array<string,mixed>> $deckItems
	 */
	public function toJson(array $deckItems): string
	{
		return (string)json_encode($deckItems, JSON_PRETTY_PRINT);
	}

	/**
	 * Export deck items as a CSV string.
	 *
	 * @param array<string|int,array<string,mixed>> $deckItems
	 */
	public function toCsv(array $deckItems): string
	{
		if ($deckItems === []) {
			return Writer::fromString('')->toString();
		}

		// Collect all unique keys across all items for the header
		$headers = [];
		foreach ($deckItems as $item) {
			foreach (array_keys($item) as $key) {
				$headers[(string)$key] = true;
			}
		}
		$headers = array_keys($headers);

		// Build CSV rows
		$rows   = [];
		$rows[] = $headers;
		foreach ($deckItems as $item) {
			$row = [];
			foreach ($headers as $header) {
				$value = $item[$header] ?? '';
				if (is_array($value)) {
					$value = (string)json_encode($value);
				} elseif (is_bool($value)) {
					$value = $value ? 'true' : 'false';
				}
				$row[] = str_replace(["\r\n", "\r", "\n"], ['\\n', '\\n', '\\n'], (string)$value);
			}
			$rows[] = $row;
		}

		$csv = Writer::fromString('');
		$csv->insertAll($rows);

		return $csv->toString();
	}
}
