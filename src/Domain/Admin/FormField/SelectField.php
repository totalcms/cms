<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class SelectField extends FormField
{
	protected string $defaultFieldType = 'select';
	protected string $defaultInputType = 'select';

	public function init(): void
	{
		parent::init();
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'multiple'         => $this->multiple ? '' : null,
			'size'             => $this->rows ? (string)$this->rows : null,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	/** @param array<mixed> $options */
	public function setOptions(array $options): void
	{
		$this->options = $options;
	}

	protected function convertOptionsList(): void
	{
		// this method takes a simple list of options and converts it to a list of associative arrays
		if (empty($this->options) || !is_string($this->options[0])) {
			return;
		}

		$this->options = array_map(fn ($o) => ['value' => $o, 'label' => $o], $this->options);
	}

	/** @return array<string,string> */
	protected function optionFromString(string $option): array
	{
		return ['value' => $option, 'label' => $option];
	}

	protected function placeholderOption(): string
	{
		if (empty($this->placeholder)) {
			return '';
		}

		$attributes = ['value' => '', 'disabled' => ''];
		if (empty($this->value)) {
			$attributes['selected'] = '';
		}

		return HTMLUtils::element('option', $this->placeholder, $attributes);
	}

	/** @param array<string,string> $option */
	protected function buildOption(array $option): string
	{
		$attributes = ['value' => $option['value']];
		if ($option['value'] == $this->value) {
			$attributes['selected'] = '';
		}
		return HTMLUtils::element('option', $option['label'], $attributes);
	}

	/** @param array<string|array<string,string>> $options */
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

	protected function buildOptions(): string
	{
		$options = '';

		$options .= $this->placeholderOption();

		foreach ($this->options as $key => $option) {
			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}
			$options .= is_string($key) ? $this->buildOptionGroup($key, $option) : $this->buildOption($option);
		}

		return $options;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::element('select', $this->buildOptions(), $attributes);
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

*/
