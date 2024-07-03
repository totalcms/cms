<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\Config;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Admin\FormField\DeleteButton;

/**
 * Total Form Builder.
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
	public function builder(array $options = []): TotalForm
	{
		$options['api']               = $this->api;
		$options['objectFetcher']     = $this->objectFetcher;
		$options['collectionFetcher'] = $this->collectionFetcher;
		$options['schemaFetcher']     = $this->schemaFetcher;
		$options['schemaLister']      = $this->schemaLister;

		return new TotalForm(...$options);
	}

	public function save(string $label = "Save"): string
	{
		$button = new SaveButton($label);
		return $button->build();
	}

	public function delete(string $label = "Delete"): string
	{
		$button = new DeleteButton($label);
		return $button->build();
	}

	/** @param array<string,mixed> $options */
	public function checkbox(string $id, array $options = []): string
	{
		$options = array_merge([
			'id'         => $id,
			'collection' => 'toggle',
			'hideID'     => true,
			'autosave'   => true,
		], $options);

		$form = $this->builder($options);

		$form->addField('id');
		$form->addField('status', ['field' => 'checkbox']);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function text(string $id, array $options = []): string
	{
		$options = array_merge([
			'id'         => $id,
			'collection' => 'text',
			'hideID'     => true,
		], $options);

		$form = $this->builder($options);

		$form->addField('id');
		$form->addField('text', ['field' => 'text']);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function textarea(string $id, array $options = []): string
	{
		$options = array_merge([
			'id'         => $id,
			'collection' => 'text',
			'hideID'     => true,
		], $options);

		$form = $this->builder($options);

		return $form->autoBuild();
	}
}
