<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Utils\HTMLUtils;
use TotalCMS\Domain\Twig\TotalCMSTwigAdapter;

/**
 * Total Table Builder.
 *
 */
final class CollectionTable
{
	private SchemaData $schemaData;
	/** @var array<array<string,mixed>> */
	private array $objects;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private IndexReader $collectionReader,
		private string $api,
		private string $collection,
	) {
		$collectionData = $this->collectionFetcher->fetchCollection($this->collection);

		if (is_null($collectionData)) {
			throw new \RuntimeException("Collection {$this->collection} not found.");
		}

		$this->schemaData = $this->schemaFetcher->fetchSchema($collectionData->schema);
		$index            = $this->collectionReader->fetchIndex($this->collection);
		$this->objects    = is_null($index) ? [] : $index->objects->toArray();
	}

	private function getPropertyType(string $property): string
	{
		$propertyData = $this->schemaData->properties[$property];
		if (isset($propertyData['type'])) {
			return $propertyData['type'];
		}
		if (isset($propertyData['$ref'])) {
			return basename($propertyData['$ref'], '.json');
		}
		return 'string';
	}

	private function buildTableHead(): string
	{
		$headings = '';
		$properties = array_keys($this->objects[0]);
		foreach ($properties as $property) {
			$class = 'schema-' . $this->getPropertyType((string) $property);
			$headings .= HTMLUtils::element('th', $property, ['class' => $class]);
		}
		$row = HTMLUtils::element('tr', $headings);
		return HTMLUtils::element('thead', $row);
	}

	/** @param array<string,mixed> $image */
	private function imagePreivew(string $id, string $property, array $image): string
	{
		$imageworks = ['w' => 64, 'h' => 63, 'fit' => 'crop-focalpoint'];
		$options = ['collection' => $this->collection, 'property' => $property];
		$imageSrc = TotalCMSTwigAdapter::buildImageworksAPI(
			api: $this->api,
			id: $id,
			image: $image,
			imageworks: $imageworks,
			options: $options
		);

		return HTMLUtils::element('img', '', [
			'src'    => $imageSrc,
			'alt'    => $image['alt'],
			'width'  => 64,
			'height' => 64,
		]);
	}

	/** @SuppressWarnings(PHPMD.CyclomaticComplexity) */
	private function formatCellData(string $id, string $property, string $type, mixed $value): string
	{
		switch ($type) {

			case 'boolean':
				return $value ? '✔' : 'X';

			case 'color':
				return HTMLUtils::element('div', $value['hex'], [
					'class' => 'color-preview',
					'style' => "background-color: {$value['hex']}"
				]);

			case 'deck':
			case 'depot':
			case 'array':
				return (string)count($value);

			case 'image':
				return $this->imagePreivew($id, $property, $value);

			case 'gallery':
				if (count($value) === 0) {
					return '';
				}
				return $this->imagePreivew($id, $property, $value[0]);

			case 'file':
				return $value['name'];

			case 'list':
				return implode(', ', $value);

			case 'password':
				return '********';
		}
		return (string)$value;
	}

	private function buildTableBody(): string
	{
		$rows = '';
		foreach ($this->objects as $object) {
			$cell = '';
			foreach ($object as $property => $value) {
				$type = $this->getPropertyType($property);
				$data = $this->formatCellData(
					id: $object['id'],
					property: $property,
					type: $type,
					value: $value,
				);
				$cell .= HTMLUtils::element('td', $data, ['class' => $type . '-data']);
			}
			$rows .= HTMLUtils::element('tr', $cell, [
				'data-object-id' => $object['id'],
			]);
		}
		return HTMLUtils::element('tbody', $rows);
	}


	public function build(): string
	{
		$table = $this->buildTableHead() . $this->buildTableBody();
		return HTMLUtils::element('table', $table, [
			'class'           => 'collection-table',
			'data-collection' => $this->collection,
		]);
	}
}
