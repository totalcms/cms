<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\TotalCMSTwigAdapter;
use TotalCMS\Utils\HTMLUtils;

/**
 * Total Table Builder.
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
		if (isset($this->schemaData->properties[$property])) {
			$propertyData = $this->schemaData->properties[$property];
			if (isset($propertyData['type'])) {
				return $propertyData['type'];
			}
			if (isset($propertyData['$ref'])) {
				return basename($propertyData['$ref'], '.json');
			}
		}

		return 'string';
	}

	private function buildTableHead(): string
	{
		$headings   = '';
		// order the columns by the index in the schema
		$properties = $this->schemaData->index;
		foreach ($properties as $property) {
			$class = 'schema-' . $this->getPropertyType((string)$property);
			$headings .= HTMLUtils::element('th', $property, ['class' => $class]);
		}
		$row = HTMLUtils::element('tr', $headings);

		return HTMLUtils::element('thead', $row);
	}

	/** @param array<string,mixed> $image */
	private function imagePreivew(string $id, string $property, array $image): string
	{
		if ($image['size'] === 0) {
			return '';
		}

		$imageworks = ['w' => 128, 'h' => 128, 'q' => 30, 'fit' => 'crop-focalpoint'];
		$options    = ['collection' => $this->collection, 'property' => $property];
		$imageSrc   = TotalCMSTwigAdapter::buildImageworksAPI(
			api: $this->api,
			id: $id,
			image: $image,
			imageworks: $imageworks,
			options: $options
		);

		return HTMLUtils::element('img', '', [
			'src'    => $imageSrc,
			'alt'    => $image['alt'],
			'width'  => '128',
			'height' => '128',
		]);
	}

	private function galleryPreivew(string $id, string $property, int $count = 0): string
	{
		$imageworks = ['w' => 128, 'h' => 128, 'q' => 30, 'fit' => 'crop-focalpoint'];
		$options    = ['collection' => $this->collection, 'property' => $property];
		$imageSrc   = TotalCMSTwigAdapter::buildImageworksGalleryAPI(
			baseapi: $this->api,
			id: $id,
			name: 'first',
			image: [],
			imageworks: $imageworks,
			options: $options
		);

		$badge = HTMLUtils::element('span', (string)$count, ['class' => 'image-count']);
		$image = HTMLUtils::element('img', '', [
			'src'    => $imageSrc,
			'alt'    => "{$this->collection} / {$id} / {$property} gallery preview",
			'width'  => '128',
			'height' => '128',
		]);

		return HTMLUtils::element('div', $image . $badge, ['class' => 'gallery-preview']);
	}

	/** @SuppressWarnings("PHPMD.CyclomaticComplexity") */
	private function formatCellData(string $id, string $property, string $type, mixed $value): string
	{
		if (is_null($value) || $value === '') {
			return '';
		}

		switch ($type) {
			case 'boolean':
				return $value ? '✔' : '';

			case 'color':
				return HTMLUtils::element('div', $value['hex'], [
					'class' => 'color-preview',
					'style' => "background-color: {$value['hex']}",
				]);

			case 'deck':
			case 'depot':
			case 'array':
				return (string)count($value);

			case 'date':
				return date('Y-m-d H:m', strtotime($value));

			case 'image':
				return $this->imagePreivew($id, $property, $value);

			case 'gallery':
				if (count($value) === 0) {
					return '';
				}

				return $this->galleryPreivew($id, $property, count($value));

			case 'file':
				return $value['name'];

			case 'list':
				return implode(', ', $value);

			case 'svg':
				return $value;

			case 'password':
				return '********';
		}

		return strip_tags((string)$value);
	}

	private function buildTableBody(): string
	{
		$rows = '';
		foreach ($this->objects as $object) {
			$cell = '';
			// order the columns by the index in the schema
			$properties = $this->schemaData->index;
			foreach ($properties as $property) {
				$type = $this->getPropertyType($property);
				$data = $this->formatCellData(
					id: $object['id'],
					property: $property,
					type: $type,
					value: $object[$property] ?? '',
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
			'class'            => 'admin-table',
			'data-limit'       => '25',
			'data-search'      => 'true',
			'data-sort'        => 'true',
			'data-placeholder' => 'Filter Objects',
		]);
	}
}
