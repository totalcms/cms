<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class MulticheckboxField extends FormField
{
	protected string $defaultInputType = 'checkbox';
	protected string $defaultFieldType = 'multicheckbox';

	public function build(): string
	{
		$label      = $this->buildFieldLabel();
		$checkboxes = $this->buildCheckboxOptions();
		$help       = $this->buildHelpText();

		$formFieldAttributes = [
			'class'     => "form-field {$this->field}-field {$this->class}",
			'data-type' => $this->field,
			'style'     => "grid-area: {$this->name};",
		];

		// Store required state on container for JavaScript validation
		if ($this->required) {
			$formFieldAttributes['data-required'] = 'true';
		}

		if ($this->settings !== []) {
			$json = json_encode($this->settings);
			if ($json) {
				$formFieldAttributes['data-options'] = $json;
			}
			if (isset($this->settings['fieldGrid'])) {
				$formFieldAttributes['style'] .= '--fieldset-grid-size:' . $this->settings['fieldGrid'] . ';';
			}
		}

		// Handle visibility settings
		$this->applyVisibility($formFieldAttributes);

		$fieldset = HTMLUtils::element('fieldset', $label . $checkboxes);

		return HTMLUtils::element('div', $fieldset . $help, $formFieldAttributes);
	}

	protected function buildFieldLabel(): string
	{
		if ($this->label === '') {
			return '';
		}

		return HTMLUtils::element('legend', $this->label);
	}

	protected function buildCheckboxOptions(): string
	{
		$this->processOptions();

		$checkboxesHtml = '';
		$index          = 1;

		foreach ($this->options as $key => $option) {
			// Check if this is a grouped option (string key with array value)
			if (is_string($key) && is_array($option)) {
				$checkboxesHtml .= $this->buildCheckboxGroup($key, $option, $index);
				$index += count($option);
			} else {
				// Regular option
				if (is_string($option)) {
					$option = $this->optionFromString($option);
				}

				$checkboxesHtml .= $this->buildSingleCheckbox($option, $index);
				$index++;
			}
		}

		return $checkboxesHtml;
	}

	/**
	 * Build a group of checkboxes with a legend.
	 *
	 * @param array<mixed> $options Array of strings or arrays with value/label
	 */
	protected function buildCheckboxGroup(string $groupLabel, array $options, int &$index): string
	{
		$groupHtml = '';

		foreach ($options as $option) {
			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}

			$groupHtml .= $this->buildSingleCheckbox($option, $index);
			$index++;
		}

		$legend  = HTMLUtils::element('legend', $groupLabel, ['class' => 'checkbox-group-legend']);
		$content = $legend . $groupHtml;

		return HTMLUtils::element('fieldset', $content, ['class' => 'multicheckbox-group']);
	}

	/** @param array<string,string> $option */
	protected function buildSingleCheckbox(array $option, int $index): string
	{
		$checkboxId = "field-{$this->uuid}-{$index}";
		$isChecked  = $this->isOptionSelected($option['value']);

		$inputAttributes = [
			'id'               => $checkboxId,
			'name'             => $this->name,
			'type'             => 'checkbox',
			'class'            => 'checkbox',
			'value'            => $option['value'],
			'disabled'         => $this->disabled ? '' : null,
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
			'checked'          => $isChecked ? '' : null,
		];

		// Remove null values from the attributes array
		$inputAttributes = array_filter($inputAttributes, fn (?string $x): bool => !is_null($x));

		$input = HTMLUtils::inlineElement('input', $inputAttributes);
		$label = HTMLUtils::element('label', $option['label'], [
			'for'   => $checkboxId,
			'class' => 'checkbox-label',
		]);

		return HTMLUtils::element('div', $input . $label, ['class' => 'checkbox']);
	}

	protected function isOptionSelected(string $optionValue): bool
	{
		$currentValue = $this->getValue();

		// Support array values
		if (is_array($currentValue)) {
			return in_array($optionValue, $currentValue, true);
		}

		// Support single value
		return (string)$currentValue === $optionValue;
	}

	protected function buildHelpText(): string
	{
		if ($this->help === '') {
			return '';
		}

		return HTMLUtils::element('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);
	}

	protected function processOptions(): void
	{
		// Options are already set via constructor parameter
		if ($this->options === []) {
			return;
		}

		// If already in correct format (array of arrays with value/label), use as-is
		if (self::isMultiDimensionalArray($this->options)) {
			return;
		}

		// Convert simple array to value/label pairs
		$processedOptions = [];
		foreach ($this->options as $key => $value) {
			$processedOptions[] = [
				'label' => is_string($key) ? $key : $value,
				'value' => $value,
			];
		}
		$this->options = $processedOptions;
	}

	/**
	 * Get the field value, ensuring proper array handling.
	 *
	 * @return mixed
	 */
	public function getValue(): mixed
	{
		$value = parent::getValue();

		// Ensure we return an array for array values, or the value as-is
		if ($value === null || $value === '') {
			return [];
		}

		return $value;
	}
}
