<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Renders admin table rows for collection query results.
 *
 * Enriches query results with collection/schema context
 * and renders each row via the internal Twig template.
 */
readonly class AdminTableRenderer
{
	public function __construct(
		private TwigEngine $twigEngine,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private ObjectUrlBuilder $objectUrlBuilder,
	) {
	}

	/**
	 * Render query results as admin table rows with an optional HTMX sentinel.
	 *
	 * @param array<string,string> $params  Query parameters (must include _collection)
	 * @param string               $baseUrl Full base URL for pagination links
	 *
	 * @throws \RuntimeException If _collection is missing or collection not found
	 */
	public function render(
		QueryResult $result,
		array $params,
		string $baseUrl,
	): string {
		$collection = $params['_collection'] ?? '';
		if ($collection === '') {
			throw new \RuntimeException('The "_collection" parameter is required for table format.');
		}

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
			throw new \RuntimeException("Collection '{$collection}' not found.");
		}

		$schemaData    = $this->schemaFetcher->fetchSchema($collectionData->schema);
		$labelSingular = $collectionData->labelSingular !== '' ? $collectionData->labelSingular : 'Object';
		$collectionUrl = $collectionData->url;

		// Build columns array from schema index
		$columns = [];
		foreach ($schemaData->index as $property) {
			$columns[] = [
				'name' => $property,
				'type' => $this->getPropertyType($schemaData, $property),
			];
		}

		$html = '';
		foreach ($result->items as $item) {
			$objectUrl = '';
			if ($collectionUrl !== '') {
				$objectUrl = $this->objectUrlBuilder->buildUrl($collectionData, $item);
			}

			$html .= $this->twigEngine->render('admin/collection/table-row.twig', [
				'object'         => $item,
				'_collection'    => $collection,
				'_columns'       => $columns,
				'_labelSingular' => $labelSingular,
				'_collectionUrl' => $collectionUrl,
				'_objectUrl'     => $objectUrl,
			]);
		}

		// Build sentinel <tr> for next page if there are more results
		if ($result->hasMore()) {
			$nextParams             = $params;
			$nextParams['format']   = 'table';
			$nextParams['offset']   = (string) $result->nextOffset();
			$nextParams['limit']    = (string) $result->limit;
			$sentinelUrl            = $baseUrl . '?' . http_build_query($nextParams);
			$colspan                = (string) (count($columns) + 1);
			$html .= '<tr class="htmx-sentinel" hx-get="' . htmlspecialchars($sentinelUrl) . '" hx-trigger="revealed" hx-swap="outerHTML">';
			$html .= '<td colspan="' . $colspan . '"><span class="loading-dots"></span></td>';
			$html .= '</tr>';
		}

		return $html;
	}

	/**
	 * Determine the property type from schema definition.
	 */
	private function getPropertyType(SchemaData $schemaData, string $property): string
	{
		if (isset($schemaData->properties[$property])) {
			$propertyData = $schemaData->properties[$property];
			if (isset($propertyData['type'])) {
				return $propertyData['type'];
			}
			if (isset($propertyData['$ref'])) {
				return basename((string) $propertyData['$ref'], '.json');
			}
		}

		return 'string';
	}
}
