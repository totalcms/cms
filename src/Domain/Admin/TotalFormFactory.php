<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\Config;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * This class is a factory for creating TotalForm objects.
 * I cannot use Dependency Injection in a non-constructor, so I need to create a factory class
 * This encapsulates the creation of the TotalForm object without depencency injection here.
 */
final class TotalFormFactory
{
	private string $api;

	public function __construct(
		private Config $config,
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private SchemaLister $schemaLister,
	) {
		$this->api = $this->config->api;
	}

	/** @param array<string,mixed> $options */
	public function factory(string $collection, array $options = []): string
	{
		$options['api']        = $this->api;
		$options['collection'] = $collection;

		$form = new FactoryForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function collection(array $options = []): string
	{
		$options = array_merge([
			'id'   => '',
			'save' => 'Save',
		], $options, [
			// These options cannot be overridden
			'api'               => $this->api,
			'collectionFetcher' => $this->collectionFetcher,
			'schemaFetcher'     => $this->schemaFetcher,
			'schemaLister'      => $this->schemaLister,
			'helpStyle'         => 'label',
			'helpOnHover'       => true,
			'helpOnFocus'       => false,
		]);

		$form = new CollectionForm(...$options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function builder(string $collection, array $options = []): ObjectForm
	{
		$options['collection']        = $collection;
		$options['api']               = $this->api;
		$options['objectFetcher']     = $this->objectFetcher;
		$options['collectionFetcher'] = $this->collectionFetcher;
		$options['schemaFetcher']     = $this->schemaFetcher;
		$options['schemaLister']      = $this->schemaLister;

		return new ObjectForm(...$options);
	}

	/** @param array<string,mixed> $options */
	private function singleFieldFormBuilder(string $id, string $defaultCollection, string $property, string $field, array $options = []): string
	{
		$collection = $options['collection'] ?? $defaultCollection;

		$form = $this->builder($collection, [
			'id'       => $id,
			'hideID'   => true,
			'save'     => $options['save'] ?? '',
			'delete'   => $options['delete'] ?? '',
			'autosave' => $options['autosave'] ?? false,
		]);

		$formOptions = ['collection', 'save', 'delete', 'autosave'];
		foreach ($formOptions as $option) {
			unset($options[$option]);
		}

		$options['field'] = $field;

		$form->addField('id');
		$form->addField($property, $options);

		return $form->build();
	}

	public function save(string $label = 'Save'): string
	{
		$button = new SaveButton($label);

		return $button->build();
	}

	public function delete(string $label = 'Delete'): string
	{
		$button = new DeleteButton($label);

		return $button->build();
	}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @param array<string,mixed> $options
	 */
	public function blog(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'blog',
			'save'       => 'Save',
			'delete'     => 'Delete',
			'fields'     => [],
		], $options);

		$fields = array_merge([
			'date'       => true,
			'summary'    => true,
			'content'    => true,
			'author'     => true,
			'tags'       => true,
			'featured'   => true,
			'draft'      => true,
			'image'      => true,
			'categories' => false,
			'extra'      => false,
			'extra2'     => false,
			'media'      => false,
			'genre'      => false,
			'labels'     => false,
			'archived'   => false,
			'gallery'    => false,
		], $options['fields']);
		// remove fields from options since it's not a valid option for TotalForm
		unset($options['fields']);

		// {% set tags       = selectOptions(cms.property(collection, "tags")) %}
		// {% set categories = selectOptions(cms.property(collection, "categories")) %}
		// {% set labels     = selectOptions(cms.property(collection, "labels")) %}
		// {% set genres     = selectOptions(cms.property(collection, "genre")) %}
		// {% set authors    = selectOptions(cms.property(collection, "authors")) %}

		$form = $this->builder($options['collection'], $options);

		$col1  = $form->field('id');
		$col1 .= $form->field('created', ['field' => 'hidden']);
		$col1 .= $form->field('updated', ['field' => 'hidden']);
		$col1 .= $form->field('title');
		if ($fields['date']) {
			$col1 .= $form->field('date');
		}
		if ($fields['media']) {
			$col1 .= $form->field('media', ['field' => 'url']);
		}
		if ($fields['summary']) {
			$col1 .= $form->field('summary', ['field' => 'styledtext']);
		}
		if ($fields['content']) {
			$col1 .= $form->field('content', ['field' => 'styledtext']);
		}
		if ($fields['extra']) {
			$col1 .= $form->field('extra', ['field' => 'styledtext']);
		}
		if ($fields['extra2']) {
			$col1 .= $form->field('extra2', ['field' => 'styledtext']);
		}

		$col2 = '';
		if ($fields['author']) {
			$col2 .= $form->field('author');
		}
		if ($fields['genre']) {
			$col2 .= $form->field('genre');
		}
		if ($fields['tags']) {
			$col2 .= $form->field('tags', ['field' => 'list']);
		}
		if ($fields['categories']) {
			$col2 .= $form->field('categories', ['field' => 'list']);
		}
		if ($fields['labels']) {
			$col2 .= $form->field('labels', ['field' => 'list']);
		}

		$inline = '';
		if ($fields['featured']) {
			$inline .= $form->field('featured', ['field' => 'toggle', 'help' => false]);
		}
		if ($fields['draft']) {
			$inline .= $form->field('draft', ['field' => 'toggle', 'help' => false]);
		}
		if ($fields['archived']) {
			$inline .= $form->field('archived', ['field' => 'toggle', 'help' => false]);
		}
		$col2 .= $form->layoutInline($inline);

		if ($fields['image']) {
			$col2 .= $form->field('image');
		}
		if ($fields['gallery']) {
			$col2 .= $form->field('gallery');
		}

		$layout = $form->layout2Columns($col1, $col2);

		return $form->build($layout);
	}

	/** @param array<string,mixed> $options */
	public function checkbox(string $id, array $options = []): string
	{
		$options['autosave'] = true;
		return $this->singleFieldFormBuilder($id, 'toggle', 'status', 'checkbox', $options);
	}

	/** @param array<string,mixed> $options */
	public function color(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'color', 'color', 'color', $options);
	}

	/** @param array<string,mixed> $options */
	public function date(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'date', 'date', 'date', $options);
	}

	/** @param array<string,mixed> $options */
	public function datetime(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'date', 'date', 'datetime', $options);
	}

	/** @param array<string,mixed> $options */
	public function feed(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'feed',
			'save'       => 'Save',
			'delete'     => 'Delete',
		], $options);

		$form = $this->builder($options['collection'], $options);

		$top = $form->field('id', ['class' => 'hidden-field']);
		$top .= $form->field('created', ['field' => 'hidden']);
		$top .= $form->field('updated', ['field' => 'hidden']);

		$col1  = $form->field('title');
		$col1 .= $form->field('content', ['field' => 'styledtext']);

		$col2  = $form->field('image');
		$col2 .= $form->field('featured', ['help' => false, 'field' => 'toggle']);

		$layout = $form->layout2Columns($col1, $col2);

		return $form->build($top . $layout);
	}

	/** @param array<string,mixed> $options */
	public function gallery(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'gallery', 'gallery', 'gallery', $options);
	}

	/** @param array<string,mixed> $options */
	public function image(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'image', 'image', 'image', $options);
	}

	/** @param array<string,mixed> $options */
	public function number(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'number', $options);
	}

	/** @param array<string,mixed> $options */
	public function range(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'range', $options);
	}

	/** @param array<string,mixed> $options */
	public function select(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'select', $options);
	}

	/** @param array<string,mixed> $options */
	public function styledtext(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'styledtext', 'styledtext', 'styledtext', $options);
	}

	/** @param array<string,mixed> $options */
	public function svg(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'svg', 'svg', 'svg', $options);
	}

	/** @param array<string,mixed> $options */
	public function text(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'text', $options);
	}

	/** @param array<string,mixed> $options */
	public function textarea(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'textarea', $options);
	}

	/** @param array<string,mixed> $options */
	public function toggle(string $id, array $options = []): string
	{
		$options['autosave'] = true;
		return $this->singleFieldFormBuilder($id, 'toggle', 'status', 'toggle', $options);
	}

	/** @param array<string,mixed> $options */
	public function url(string $id, array $options = []): string
	{
		return $this->singleFieldFormBuilder($id, 'url', 'url', 'url', $options);
	}
}
