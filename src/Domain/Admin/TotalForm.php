<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/**
 * Total Form Builder.
 */
final class TotalForm
{
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

		$this->collectionData = $this->collectionFetcher->fetchCollection($this->collection);
		$this->schemaData     = $this->schemaFetcher->fetchSchema($this->collectionData->schema);
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
			$attributes['data-new-action'] = json_encode($this->newAction);
		}
		if ($this->editAction) {
			$attributes['data-edit-action'] = json_encode($this->editAction);
		}

		return self::createHTMLElement('form', $this->fieldContent(), $attributes);
	}

	private function fieldContent(): string
	{
		return 'Field Content';
	}

	public function addField(): void
	{
		return;
	}

	public static function createHTMLElement(string $tag, string $content, array $attributes = []): string
	{
		// Start the element with the opening tag
		$element = "<$tag";

		// Add attributes to the tag
		foreach ($attributes as $attr => $value) {
			if ($value !== false) { // Example condition: add attribute if its value is not false
				$element .= " $attr=\"" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
			}
		}

		// Close the opening tag and add content
		$element .= ">$content</$tag>";

		return $element;
	}

	public function __toString()
	{
		return $this->build();
	}
}
