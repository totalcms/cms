<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Shared base for fields that render a list of choices as individual
 * inputs (radio buttons or checkboxes) inside a <fieldset>.
 *
 * Subclasses declare their per-variant CSS classes and `type` attribute via
 * class constants, and implement `isOptionSelected()` for their value semantics.
 */
abstract class ChoiceField extends FormField
{
	/** The `type` attribute emitted on each <input>. */
	protected const INPUT_TYPE = '';

	/** Class on each option's wrapper <div>. */
	protected const OPTION_CLASS = '';

	/** Class on each option's <label>. */
	protected const LABEL_CLASS = '';

	/** Class on the nested <fieldset> wrapping a named group of options. */
	protected const GROUP_FIELDSET_CLASS = '';

	/** Class on a grouped fieldset's <legend>. */
	protected const GROUP_LEGEND_CLASS = '';

	/** Class added to the field wrapper when settings.fieldColumns is set. */
	protected const COLUMNS_CLASS = 'choice-field--columns';

	/** Optional class emitted on the <input> element itself (empty = no class attr). */
	protected const INPUT_CLASS = '';

	/**
	 * Whether to emit `required` on each individual <input>. Radio groups need
	 * this for native HTML validation; multicheckbox cannot because it would
	 * require every box to be checked.
	 */
	protected const REQUIRED_ON_OPTION = false;

	/**
	 * Whether to mirror the required state as `data-required` on the container
	 * for JS-driven validation. Used by multicheckbox.
	 */
	protected const REQUIRED_ON_CONTAINER = false;

	public function build(): string
	{
		$choices = $this->renderChoices();

		$extraStyles  = [];
		$extraClasses = ['choice-field'];
		if (isset($this->settings['fieldGrid'])) {
			$extraStyles['--fieldset-grid-size'] = $this->settings['fieldGrid'];
		}
		if (isset($this->settings['fieldColumns'])) {
			$extraStyles['--fieldset-columns'] = $this->settings['fieldColumns'];
			$extraClasses[]                    = static::COLUMNS_CLASS;
		}

		$attributes = $this->buildFieldAttributes($extraStyles, $extraClasses);
		if (static::REQUIRED_ON_CONTAINER && $this->required) {
			$attributes['data-required'] = 'true';
		}

		$fieldset = HTMLUtils::element('fieldset', $this->createFieldLabel('legend') . $choices);

		return HTMLUtils::element('div', $fieldset . $this->createHelpText(), $attributes);
	}

	/**
	 * Iterate `$this->options`, emitting grouped and ungrouped choices as HTML.
	 */
	protected function renderChoices(): string
	{
		$this->processOptions();

		$html  = '';
		$index = 1;

		foreach ($this->options as $key => $option) {
			// Grouped entry: string key with an array of options
			if (is_string($key) && is_array($option)) {
				$html .= $this->renderChoiceGroup($key, $option, $index);
				$index += count($option);
				continue;
			}

			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}

			$html .= $this->renderChoice($option, $index);
			$index++;
		}

		return $html;
	}

	/**
	 * Render a named group of choices as a nested <fieldset> with its own <legend>.
	 *
	 * @param array<mixed> $options Option strings or [value,label] arrays
	 */
	protected function renderChoiceGroup(string $groupLabel, array $options, int &$index): string
	{
		$groupHtml = '';
		foreach ($options as $option) {
			if (is_string($option)) {
				$option = $this->optionFromString($option);
			}
			$groupHtml .= $this->renderChoice($option, $index);
			$index++;
		}

		$legend = HTMLUtils::element('legend', $groupLabel, ['class' => static::GROUP_LEGEND_CLASS]);

		return HTMLUtils::element('fieldset', $legend . $groupHtml, ['class' => static::GROUP_FIELDSET_CLASS]);
	}

	/**
	 * Render a single choice input + label pair, wrapped in a div.
	 *
	 * @param array<string,string> $option
	 */
	protected function renderChoice(array $option, int $index): string
	{
		$optionId  = "field-{$this->uuid}-{$index}";
		$isChecked = $this->isOptionSelected($option['value']);

		$inputAttributes = [
			'id'               => $optionId,
			'name'             => $this->name,
			'type'             => static::INPUT_TYPE,
			'value'            => $option['value'],
			'disabled'         => $this->disabled ? '' : null,
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
			'checked'          => $isChecked ? '' : null,
		];

		if (static::INPUT_CLASS !== '') {
			$inputAttributes['class'] = static::INPUT_CLASS;
		}
		if (static::REQUIRED_ON_OPTION) {
			$inputAttributes['required'] = $this->required ? '' : null;
		}

		$inputAttributes = array_filter($inputAttributes, fn ($x): bool => !is_null($x));

		$input = HTMLUtils::inlineElement('input', $inputAttributes);
		$label = HTMLUtils::element('label', $option['label'], [
			'for'   => $optionId,
			'class' => static::LABEL_CLASS,
		]);

		return HTMLUtils::element('div', $input . $label, ['class' => static::OPTION_CLASS]);
	}

	/**
	 * Whether a given option value is currently selected. Subclasses encode
	 * their value semantics (single value for radio, array membership for multicheckbox).
	 */
	abstract protected function isOptionSelected(string $optionValue): bool;

	/**
	 * Resolve settings-driven option sources (propertyOptions, relationalOptions,
	 * accessGroupOptions) via the base FormField::buildOptions(), then normalize
	 * flat key/value arrays into the [value, label] shape each choice renderer expects.
	 */
	protected function processOptions(): void
	{
		$this->buildOptions();

		if ($this->options !== [] && !self::isMultiDimensionalArray($this->options)) {
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
