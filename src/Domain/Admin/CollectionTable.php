<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\IdField;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Support\Config;

/** @SuppressWarnings("PHPMD.CouplingBetweenObjects") */
final class CollectionTable
{
	private CollectionData $collectionData;
	private SchemaData $schemaData;
	/** @var array<array<string,mixed>> */
	private array $objects;

	public function __construct(
		private Config $config,
		private CollectionFetcher $collectionFetcher,
		private CollectionLister $collectionLister,
		private SchemaFetcher $schemaFetcher,
		private IndexReader $collectionReader,
		private string $api,
		private string $collection,
	) {
		$collectionData = $this->collectionFetcher->fetchCollection($this->collection);

		if (is_null($collectionData)) {
			throw new \RuntimeException("Collection {$this->collection} not found.");
		}
		$this->collectionData = $collectionData;

		$this->schemaData = $this->schemaFetcher->fetchSchema($collectionData->schema);
		$index            = $this->collectionReader->fetchIndex($this->collection);
		$this->objects    = $index->objects->toArray();
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

	private function buildCloneDialog(): string
	{
		$labelSingular = $this->collectionData->labelSingular ?? 'Object';
		$header        = HTMLUtils::element('h3', 'Duplicate ' . $labelSingular);

		$collections = $this->collectionLister->listCollectionsWithSchema($this->schemaData->id);

		$options = '';
		foreach ($collections as $collection) {
			$attrs = ['value' => $collection->id];
			if ($collection->id === $this->collectionData->id) {
				$attrs['selected'] = '';
			}
			$options .= HTMLUtils::element('option', $collection->name, $attrs);
		}

		$label           = HTMLUtils::element('label', 'Clone into Collection', ['for'=>'clone-collection']);
		$input           = HTMLUtils::element('select', $options, ['id'=>'clone-collection', 'type'=>'text', 'name'=>'collection']);
		$collectionField = HTMLUtils::element('div', $label . $input);

		$label   = HTMLUtils::element('label', 'New ' . $labelSingular . ' ID', ['for' => 'clone-id']);
		$input   = HTMLUtils::inlineElement('input', [
			'id'             => 'clone-id',
			'type'           => 'text',
			'name'           => 'id',
			'autocapitalize' => 'off',
			'class'          => 'slugify-input',
		]);
		$idField = HTMLUtils::element('div', $label . $input);

		$form = new SimpleForm(
			api     : $this->api,
			route   : '', // the route is set in the javascript
			method  : 'POST',
			label   : 'Clone ' . $labelSingular,
			class   : 'clone-object-form',
			refresh : true,
		);
		$content = $form->build($header . $collectionField . $idField);

		return HTMLUtils::dialog($content, 'dialog-clone-object small');
	}

	private function buildTableHead(): string
	{
		$headings = HTMLUtils::element('th', 'action-button', ['class' => 'action']);

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
			'src'     => $imageSrc,
			'alt'     => $image['alt'],
			'width'   => '128',
			'height'  => '128',
			'loading' => 'lazy',
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
			'src'     => $imageSrc,
			'alt'     => "{$this->collection} / {$id} / {$property} gallery preview",
			'width'   => '128',
			'height'  => '128',
			'loading' => 'lazy',
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
				$zone = new \DateTimeZone($this->config->timezone);
				$date = new \DateTime($value, $zone);

				// strip the time if it's 00:00
				return trim(str_replace('00:00', '', $date->format('Y-m-d H:i')));

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

	/** @return array<array<string,mixed>> */
	private function sortObjects(): array
	{
		$sortBy      = $this->collectionData->sortBy ?? 'id';
		$reverseSort = $this->collectionData->reverseSort ?? false;

		$objects = $this->objects;

		usort($objects, function ($a, $b) use ($sortBy, $reverseSort) {
			$aValue = $a[$sortBy] ?? '';
			$bValue = $b[$sortBy] ?? '';

			if ($aValue === $bValue) {
				return 0;
			}

			if ($reverseSort) {
				return ($aValue < $bValue) ? 1 : -1;
			}

			return ($aValue < $bValue) ? -1 : 1;
		});

		return $objects;
	}

	private function buildObjectActionButton(string $id): string
	{
		$link = '';
		if (!empty($this->collectionData->url)) {
			$link = HTMLUtils::element('a', 'Link to Webpage', [
				'target' => '_blank',
				'href'   => CollectionData::objectUrl($this->collectionData, $id),
			]);
			$link = HTMLUtils::element('li', $link, ['class' => 'link']);
		}

		$labelSingular = $this->collectionData->labelSingular ?? 'Object';
		$delete        = HTMLUtils::element('a', 'Delete ' . $labelSingular, [
			'class'        => 'cms-quick-action',
			'data-method'  => 'DELETE',
			'data-confirm' => 'Are you sure you want to delete this ' . strtolower($labelSingular) . '?',
			'href'         => implode('/', [
				$this->config->api,
				'collections',
				$this->collectionData->id,
				$id,
			]),
		]);
		$delete = HTMLUtils::element('li', $delete, ['class' => 'delete']);

		$clone = HTMLUtils::element('a', 'Duplicate ' . $labelSingular, [
			'href' => implode('/', [
				'/collections',
				$this->collectionData->id,
				$id,
				'clone',
			]),
		]);
		$clone = HTMLUtils::element('li', $clone, ['class' => 'clone']);

		$actions = HTMLUtils::element('ul', $link . $clone . $delete);
		$popover = HTMLUtils::element('nav', $actions, [
			'popover' => '',
			'class'   => 'object-action-popover',
			'id'      => 'object-action-' . $id,
		]);
		$button = HTMLUtils::element('button', '', [
			'class'          => 'dash-action-dots',
			'title'          => $labelSingular . ' Actions',
			'popovertarget'  => 'object-action-' . $id,
		]);

		return $button . $popover;
	}

	private function buildTableBody(): string
	{
		$rows = '';
		foreach ($this->sortObjects() as $object) {
			$button = $this->buildObjectActionButton($object['id']);
			// add the action button to the first column
			$cell = HTMLUtils::element('td', $button, ['class' => 'action']);
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

		$labelPlural = $this->collectionData->labelPlural ?? 'Objects';
		$attributes  = [
			'class'            => 'admin-table',
			'data-search'      => 'true',
			'data-sort'        => 'true',
			'data-placeholder' => 'Filter ' . $labelPlural,
		];

		$pagination = $this->config->dashboard['pagination'] ?? null;
		if (!empty($pagination) && $pagination > 0) {
			$attributes['data-limit'] = (string)$pagination;
		}

		$table = HTMLUtils::element('table', $table, $attributes);

		return $table . $this->buildCloneDialog();
	}
}
