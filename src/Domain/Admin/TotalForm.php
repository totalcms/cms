<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Utils\HTMLUtils;

/**
 * Total Form Builder.
 */
final class TotalForm
{
	/** @var array<FormField> */
	private array $fields = [];
	private string $route;
	private CollectionData $collectionData;
	private ObjectData $objectData;
	private SchemaData $schemaData;

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private SchemaLister $schemaLister,
		private string $api,
		private string $collection,
		private string $method     = 'post',
		private string $id         = '',
		private string $class      = '',
		private string $helpStyle  = '',
		private string $newAction  = '',
		private string $editAction = '',
		private bool $autosave     = false,
		private bool $helpOnHover  = false,
		private bool $helpOnFocus  = false,
	) {
		if ($this->autosave === true) {
			$this->class .= ' autosave';
		}
		if ($this->helpOnHover === true) {
			$this->class .= ' help-on-hover';
		}
		if ($this->helpOnFocus === true) {
			$this->class .= ' help-on-focus';
		}
		if (!empty($this->helpStyle)) {
			$this->class .= " help-{$this->helpStyle}";
		}

		$this->route = "/collections/{$this->collection}";

		if (empty($this->id) && isset($_GET['id'])) {
			$this->id = $_GET['id'];
		}
		if (!empty($this->id) && $this->method === 'post') {
			// If the form is for editing an existing item, change the method to PUT
			$this->method     = 'put';
			$this->objectData = $this->objectFetcher->fetchObject($this->collection, $this->id);
			$this->route      = "/collections/{$this->collection}/{$this->id}";
		}

		$collectionData   = $this->collectionFetcher->fetchCollection($this->collection);

		if (is_null($collectionData)) {
			throw new \Exception('Collection not found for TotalForm');
		}

		$this->collectionData = $collectionData;
		$this->schemaData     = $this->schemaFetcher->fetchSchema($this->collectionData->schema);
	}

	public function autoBuild(): string
	{
		// TODO: Read in the schema and build the form automatically
		return '';
	}

	public function build(): string
	{
		$attributes = [
			'class'           => "totalform {$this->class}",
			'data-schema'     => $this->collectionData->schema,
			'data-collection' => $this->collection,
			'data-method'     => $this->method,
			'data-api'        => $this->api,
			'data-route'      => $this->route,
		];

		if (!empty($this->id)) {
			$attributes['data-id'] = $this->id;
		}
		if ($this->newAction) {
			$json = json_encode($this->newAction);
			if ($json) {
				$attributes['data-new-action'] = $json;
			}
		}
		if ($this->editAction) {
			$json = json_encode($this->editAction);
			if ($json) {
				$attributes['data-edit-action'] = $json;
			}
		}

		return HTMLUtils::createHTMLElement('form', $this->fieldContent(), $attributes);
	}

	private function fieldContent(): string
	{
		$content = '';
		foreach ($this->fields as $field) {
			$content .= $field->build();
		}

		return $content;
	}

	/** @return array<string,mixed> */
	public function fieldDefaults(string $property): array
	{
		$schema = $this->schemaData->properties[$property] ?: [];
		$collection = $this->collectionData->properties[$property] ?: [];

		$defaults = array_merge($schema, $collection);
		$defaults['type'] = $defaults['field']; // Schemas use 'field' instead of 'type'

		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		$fieldDefaults = ["label", "placeholder", "help", "settings"];
		$defaults = array_filter($defaults, fn ($key) => in_array($key, $fieldDefaults), ARRAY_FILTER_USE_KEY);

		return $defaults;
	}

	/** @param array<string,mixed> $options */
	public function addField(array $options): void
	{
		if (!isset($options['name']) || empty($options['name'])) {
			throw new \Exception('FormField name is required');
		}
		if (!isset($this->schemaData->properties[$options['name']])) {
			throw new \Exception("Field '{$options['name']}' not found in schema");
		}

		$defaults = $this->fieldDefaults($options['name']);
		$options  = array_merge($defaults, $options);

		$this->fields[] = new FormField(...$options);
	}

	public function __toString()
	{
		return $this->build();
	}
}
