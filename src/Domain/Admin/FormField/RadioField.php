<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

final class RadioField extends FormField
{
	protected string $defaultInputType = 'radio';
	protected string $defaultFieldType = 'radio';

	public function build(): string
	{
		$label  = $this->buildFieldLabel();
		$radios = $this->buildRadioOptions();
		$help   = $this->buildHelpText();

		$formFieldAttributes = [
			'class'     => "form-field {$this->field}-field {$this->class}",
			'data-type' => $this->field,
			'style'     => "grid-area: {$this->name};",
		];

		if ($this->settings !== []) {
			$json = json_encode($this->settings);
			if ($json) {
				$formFieldAttributes['data-options'] = $json;
			}
			if (isset($this->settings['fieldGrid'])) {
				$formFieldAttributes['style'] .= '--fieldset-grid-size:' . $this->settings['fieldGrid'] . ';';
			}
		}

		$fieldset = HTMLUtils::element('fieldset', $label . $radios);

		return HTMLUtils::element('div', $fieldset . $help, $formFieldAttributes);
	}

	protected function buildFieldLabel(): string
	{
		if ($this->label === '') {
			return '';
		}

		return HTMLUtils::element('legend', $this->label);
	}

	protected function buildRadioOptions(): string
	{
		$this->processOptions();

		$radiosHtml = '';
		$index = 1;

		foreach ($this->options as $option) {
			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}

			$radiosHtml .= $this->buildSingleRadio($option, $index);
			$index++;
		}

		return $radiosHtml;
	}

	/** @param array<string,string> $option */
	protected function buildSingleRadio(array $option, int $index): string
	{
		$radioId = "field-{$this->uuid}-{$index}";
		$isChecked = $this->isOptionSelected($option['value']);

		$inputAttributes = [
			'id'               => $radioId,
			'name'             => $this->name,
			'type'             => 'radio',
			'value'            => $option['value'],
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
			'checked'          => $isChecked ? '' : null,
		];

		// Remove null values from the attributes array
		$inputAttributes = array_filter($inputAttributes, fn ($x) => !is_null($x));

		$input = HTMLUtils::inlineElement('input', $inputAttributes);
		$label = HTMLUtils::element('label', $option['label'], [
			'for' => $radioId,
			'class' => 'radio-label'
		]);

		return HTMLUtils::element('div', $input . $label, ['class' => 'radio']);
	}

	protected function isOptionSelected(string $optionValue): bool
	{
		return (string)$this->value === $optionValue;
	}

	protected function buildHelpText(): string
	{
		if (empty($this->help)) {
			return '';
		}

		return HTMLUtils::element('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);
	}

	protected function processOptions(): void
	{
		// Process options using parent class functionality
		$this->buildOptions();

		// Ensure options are in the correct format
		if (!empty($this->options) && !self::isMultiDimensionalArray($this->options)) {
			// Convert simple array to key-value pairs
			$processedOptions = [];
			foreach ($this->options as $key => $value) {
				$processedOptions[] = [
					'label' => is_string($key) ? $key : $value,
					'value' => $value,
				];
			}
			$this->options = $processedOptions;
		}
	}
}
