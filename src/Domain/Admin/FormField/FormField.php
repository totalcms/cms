<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Utils\HTMLUtils;

/**
 *  @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 *  @SuppressWarnings("PHPMD.NumberOfChildren")
 */
class FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'text';

	protected string $uuid;
	protected bool $datalist = false;

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected TotalForm $form,
		protected string $name,
		protected string $class       = '',
		protected string $field       = '',
		protected string $inputType   = '',
		protected string $label       = '',
		protected string $placeholder = '',
		protected string $help        = '',
		protected mixed $value        = '',
		protected mixed $default      = '',
		protected string $pattern     = '',
		protected array $settings     = [],
		protected array $options      = [],
		protected bool $required      = false,
		protected bool $disabled      = false,
		protected bool $readonly      = false,
		protected bool $icon          = true,
		protected bool $multiple      = false,
		protected int $minlength      = 0,
		protected int $rows           = 0,
		protected ?int $min           = null,
		protected ?int $max           = null,
		protected ?float $step        = null,
	) {
		$this->init();
	}

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = empty($this->field) ? $this->defaultFieldType : $this->field;
		$this->inputType = empty($this->inputType) ? $this->defaultInputType : $this->inputType;

		$this->datalist = (isset($this->settings['propertyOptions'])
			|| isset($this->settings['relationalOptions'])
			|| isset($this->settings['datalistOptions']));

		// Set a default value if one is not provided
		if (empty($this->value) && !empty($this->default)) {
			$this->value = $this->default;
		}
	}

	public function build(): string
	{
		$input = $this->buildFormField();
		$icon  = $this->icon ? HTMLUtils::element('div', '', ['class' => 'form-group-icon']) : '';

		$group = $this->createFormGroup($input . $icon);

		return $this->createFormField($group);
	}

	public function createFormGroup(string $content): string
	{
		return HTMLUtils::element('div', $content, ['class' => 'form-group']);
	}

	public function createFormField(string $content): string
	{
		$label = empty($this->label) ? '' : HTMLUtils::element('label', $this->label, [
			'for' => "field-{$this->uuid}",
		]);
		$help  = empty($this->help) ? '' : HTMLUtils::element('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);

		$formFieldAtrributes = [
			'class'     => "form-field {$this->field}-field {$this->class}",
			'data-type' => $this->field,
		];
		if (!empty($this->settings)) {
			$json = json_encode($this->settings);
			if ($json) {
				$formFieldAtrributes['data-options'] = $json;
			}
		}

		$formField = HTMLUtils::element('div', $label . $content . $help, $formFieldAtrributes);

		return $formField;
	}

	/**
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 *
	 * @return array<string,?string>
	 */
	protected function formFieldAttributes(): array
	{
		if (!empty($this->value) && is_array($this->value)) {
			$this->value = json_encode($this->value);
		}

		$attributes = [
			'type'             => $this->inputType,
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'minlength'        => $this->minlength > 0 ? (string)$this->minlength : null,
			'pattern'          => empty($this->pattern) ? null : $this->pattern,
			'placeholder'      => empty($this->placeholder) ? null : $this->placeholder,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
			'value'            => empty($this->value) ? null : $this->value,
			'min'              => is_null($this->min) ? null : (string)$this->min,
			'max'              => is_null($this->max) ? null : (string)$this->max,
			'step'             => is_null($this->step) ? null : (string)$this->step,
			'list'             => $this->datalist ? "datalist-{$this->uuid}" : null,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function disable(): void
	{
		$this->disabled = true;
		$this->readonly = true;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();
		$input      = HTMLUtils::inlineElement('input', $attributes);
		$datalist   = $this->datalist ? $this->buildDatalist() : '';

		return $input . $datalist;
	}

	/** @param array<mixed> $options */
	public function setOptions(array $options): void
	{
		$this->options = $options;
	}

	/** @return array<string,string> */
	protected function optionFromString(string $option): array
	{
		return ['value' => $option, 'label' => $option];
	}

	/** @param array<string,string> $option */
	protected function buildOption(array $option): string
	{
		$attributes = ['value' => $option['value']];

		return HTMLUtils::option($option['label'], $this->value, $attributes);
	}

	/** @param array<string>|array<int,array<string,string>> $options */
	protected function buildOptionGroup(string $group, array $options): string
	{
		$groupOptions = '';
		foreach ($options as $option) {
			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}
			$groupOptions .= $this->buildOption($option);
		}

		return HTMLUtils::element('optgroup', $groupOptions, ['label' => $group]);
	}

	/** @return array<string> */
	protected function buildOptionsForProperty(): array
	{
		return $this->form->propertyListForCollection($this->name);
	}

	/** @return array<array<string,string>> */
	protected function buildRelationalOptions(): array
	{
		$settings      = $this->settings['relationalOptions'];
		$labelProperty = $settings['label'] ?? 'id';
		$valueProperty = $settings['value'] ?? 'id';
		$collection    = $settings['collection'] ?? '';

		$properties = $this->form->propertiesForCollection([$labelProperty, $valueProperty], $collection);

		// reformat the properties array to match the options array
		return array_map(fn ($o) => ['value' => $o[$valueProperty], 'label' => $o[$labelProperty]], $properties);
	}

	/**
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 */
	protected function buildOptions(string $options = ''): string
	{
		if (isset($this->settings['propertyOptions']) && $this->settings['propertyOptions'] === true) {
			$this->options = array_merge($this->options, $this->buildOptionsForProperty());
		}
		if (isset($this->settings['relationalOptions'])) {
			$this->options = array_merge($this->options, $this->buildRelationalOptions());
		}
		if (is_array($this->value) && !empty($this->value)) {
			$this->options = array_merge($this->value, $this->options); // value is first to maintain order
		}

		if (!empty($this->options) && !self::isMultiDimensionalArray($this->options)) {
			// Ensure that duplicate options are not created
			// array_unique will not work with multi-dimensional arrays
			$this->options = array_unique($this->options);
		}

		foreach ($this->options as $key => $option) {
			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}
			if (is_array($option)) {
				$options .= is_string($key) ? $this->buildOptionGroup($key, $option) : $this->buildOption($option);
			}
		}

		return $options;
	}

	/** @param array<mixed> $array */
	protected static function isMultiDimensionalArray(array $array): bool
	{
		foreach ($array as $element) {
			if (is_array($element)) {
				return true; // The array is multidimensional
			}
		}

		return false; // The array is not multidimensional
	}

	protected function buildDatalist(): string
	{
		return HTMLUtils::element('datalist', $this->buildOptions(), ['id' => "datalist-{$this->uuid}"]);
	}
}

/* Options Possibilities

Example 1: Simple list of options
$field = new SelectField(options : ['Option 1', 'Option 2', 'Option 3']);

Example 2: Options with values
$field = new SelectField(options : [
	['value' => '1', 'label' => 'Option 1'],
	['value' => '2', 'label' => 'Option 2'],
	['value' => '3', 'label' => 'Option 3'],
]);

Example 3: Grouped options
$field = new SelectField(options : [
	'Group 1' => ['Option 1', 'Option 2'],
	'Group 2' => ['Option 3', 'Option 4'],
]);

Example 4: Grouped options with values
$field = new SelectField(options : [
	'Group 1' => [
		['value' => '1', 'label' => 'Option 1'],
		['value' => '2', 'label' => 'Option 2'],
	],
	'Group 2' => [
		['value' => '3', 'label' => 'Option 3'],
		['value' => '4', 'label' => 'Option 4'],
	],
]);

### AutoBuild options via collection data

"settings": {
	"propertyOptions" : true,
	"relationalOptions" : {
		"collection" : "mycollection",
		"label"      : "name",
		"value"      : "id"
	}
},

*/
