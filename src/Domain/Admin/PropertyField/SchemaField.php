<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\SchemaForm;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Factory\Faker\FakerExamples;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;

class SchemaField extends PropertyField
{
	public const SCHEMA_PROPERTY_FIELDS = [
		'default',
		'deckref',
		'factory',
		'field',
		'help',
		'label',
		'placeholder',
		'options',
		'settings',
		'type',
	];

	/**
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<string,mixed> $extra - extra attributes for the field schema such as minItems, items, patternProperties, etc
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected TotalForm $form,
		protected string $property,
		protected string $field       = 'text',
		protected string $type        = 'string',
		protected string $label       = '',
		protected string $help        = '',
		protected string $placeholder = '',
		protected string $factory     = '',
		protected string $default     = '',
		protected string $deckref     = '',
		protected array $options      = [],
		protected array $settings     = [],
		protected array $extra        = [],
	) {
	}

	protected function topFieldInfo(): string
	{
		$content = $this->form->field('type', [
			'field'       => 'select',
			'label'       => 'Type of Data',
			'placeholder' => 'Select a data type',
			'help'        => 'The data type of the property will store in the CMS',
			'value'       => $this->type,
			'options'     => SchemaData::PROPERTY_TYPES,
		]);
		$content .= parent::topFieldInfo();

		return $content;
	}

	protected function buildPropertyInfo(): string
	{
		$content = $this->form->field('factory', [
			'field'       => 'text',
			'label'       => 'Factory',
			'placeholder' => 'text(300)',
			'help'        => 'The factory that will be used to generate the field form. See the datalist options for examples. See docs for more info.',
			'value'       => $this->factory,
			'settings'    => ['datalistOptions' => true],
			'options'     => FakerExamples::FAKER_EXAMPLES,
		]);
		$content .= $this->form->field('default', [
			'field'       => 'text',
			'label'       => 'Default Value',
			'placeholder' => '',
			'help'        => 'The default value for this property when an object is saved without a value',
			'value'       => $this->default,
		]);
		$content .= $this->form->field('deckref', [
			'field'       => 'select',
			'label'       => 'Deck Schema Reference',
			// 'placeholder' => 'Select a schema compatible with deck',
			'help'        => 'The schema reference for deck items. Only compatible schemas are shown.',
			'value'       => $this->deckref,
			'options'     => $this->getDeckCompatibleSchemaOptions(),
		]);
		$content .= $this->form->field('extra', [
			'field'       => 'json',
			'label'       => 'Extra Schema Definitions',
			'placeholder' => '{ "key": "value" }',
			'settings'    => ['rows' => 3],
			'help'        => 'Extra schema definitions for this property in valid JSON format',
			'value'       => empty($this->extra) ? '' : json_encode($this->extra, JSON_PRETTY_PRINT),
		]);

		return HTMLUtils::details('Property Info', $content);
	}

	protected function buildDialog(string $content = ''): string
	{
		$content .= $this->topFieldInfo();
		$content .= $this->buildFormInfo();
		$content .= $this->buildSettingsOptions();
		$content .= $this->buildPropertyInfo();

		$close = HTMLUtils::button('Close', ['class' => 'close']);

		$content  = HTMLUtils::scroller($content);
		$content .= HTMLUtils::element('section', $close);

		return HTMLUtils::dialog($content, 'small');
	}

	public function build(): string
	{
		$inputAttributes = [
			'autocomplete' => 'off',
			'type'         => 'text',
			'name'         => 'property',
			'placeholder'  => 'property',
			'required'     => '',
			'value'        => $this->property,
		];

		if ($this->property === 'id') {
			$inputAttributes['disabled'] = '';
			$inputAttributes['readonly'] = '';
		}

		$dialog  = $this->buildDialog();
		$input   = HTMLUtils::inlineElement('input', $inputAttributes);
		$buttons = HTMLUtils::button('', ['class' => 'edit', 'title' => "Edit {$this->property} property"]);

		if ($this->form instanceof SchemaForm && !$this->form->reserved) {
			$buttons .= HTMLUtils::button('', ['class' => 'duplicate', 'title' => "Duplicate {$this->property} property"]);
			$buttons .= HTMLUtils::button('', ['class' => 'trash', 'title' => "Delete {$this->property} property"]);
		}

		$field  = HTMLUtils::element('div', $input . $buttons . $dialog, [
			'class' => "schema-field {$this->field}-field {$this->type}-type",
		]);

		return $field;
	}

	/**
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	public static function filterSchemaProperties(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => in_array($key, self::SCHEMA_PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	public static function filterExtraProperties(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => !in_array($key, self::SCHEMA_PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * Get options for deck-compatible schemas.
	 *
	 * @return array<array<string,string>>
	 */
	private function getDeckCompatibleSchemaOptions(): array
	{
		$options = [
			['label' => '', 'value' => ''],
		];

		try {
			// Check if the form has schemaLister available
			if (!($this->form instanceof SchemaForm)) {
				return $options;
			}

			$deckChecker = new DeckCompatibilityChecker();

			// Get all schemas directly from the form's public schemaLister
			$schemas = $this->form->schemaLister->listAllSchemas();

			foreach ($schemas as $schema) {
				$schemaArray = $schema->toArray();

				// Check if the schema is deck compatible
				if ($deckChecker->isCompatible($schemaArray)) {
					// Use schema ID as label and $id as value
					$options[] = [
						'label' => $schemaArray['id'],
						'value' => $schemaArray['$id'],
					];
				}
			}
		} catch (\Exception $e) {
			// If there's an error, just return the empty select option
			// This prevents breaking the form if services aren't available
		}

		return $options;
	}
}
