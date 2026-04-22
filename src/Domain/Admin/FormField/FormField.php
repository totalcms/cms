<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

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
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-settings attribute
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
		protected int $maxlength      = 0,
		protected int $rows           = 0,
		protected ?int $min           = null,
		protected ?int $max           = null,
		protected ?float $step        = null,
		protected bool $hide          = false,
	) {
		$this->init();
	}

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = $this->field === '' ? $this->defaultFieldType : $this->field;
		$this->inputType = $this->inputType === '' ? $this->defaultInputType : $this->inputType;

		$this->datalist = ($this->options !== []
			|| isset($this->settings['propertyOptions'])
			|| isset($this->settings['relationalOptions'])
			|| isset($this->settings['accessGroupOptions'])
			|| isset($this->settings['datalistOptions']));

		// Set a default value on new object forms if one is not provided
		// Use strict comparison to preserve falsy values like false, 0, '0'
		// Only use default if value is empty string (not explicitly set)
		if (!$this->form->id && $this->value === '' && $this->default !== '') {
			$this->value = $this->default;
		}

		// Lock field when editing an existing object.
		// Sets both readonly (for input/textarea) and disabled (for select, which ignores readonly per HTML spec).
		// Values are still submitted because generateData() reads via JS field.getValue(), not HTML form submission.
		if (isset($this->settings['lockOnEdit']) && $this->settings['lockOnEdit'] === true && $this->form->isEditMode()) {
			$this->readonly = true;
			$this->disabled = true;
		}

		// Add cms-hide class if hide setting is true (check both property-level and settings)
		if ($this->hide || (isset($this->settings['hide']) && $this->settings['hide'] === true)) {
			$this->class = trim($this->class . ' cms-hide');
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
		return HTMLUtils::element('div', $this->createFieldLabel() . $content . $this->createHelpText(), $this->buildFieldAttributes());
	}

	/**
	 * Render the field's visible label. Defaults to `<label for="field-{uuid}">`;
	 * pass `'legend'` for fieldset-based fields (radio, multicheckbox).
	 */
	protected function createFieldLabel(string $tag = 'label'): string
	{
		if ($this->label === '') {
			return '';
		}

		$attributes = $tag === 'label' ? ['for' => "field-{$this->uuid}"] : [];

		return HTMLUtils::element($tag, $this->label, $attributes);
	}

	/**
	 * Render the field's help text paragraph. Returns an empty string when no
	 * help is configured. The id matches the `aria-describedby` emitted by inputs.
	 */
	protected function createHelpText(): string
	{
		if ($this->help === '') {
			return '';
		}

		return HTMLUtils::element('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);
	}

	/**
	 * Build the outer HTML attributes shared by every form-field wrapper.
	 *
	 * @param array<string,string|int|float> $extraStyles Additional CSS custom properties to append (e.g. ['--fieldset-columns' => 2])
	 * @param array<string> $extraClasses Additional class names to append
	 *
	 * @return array<string,string>
	 */
	protected function buildFieldAttributes(array $extraStyles = [], array $extraClasses = []): array
	{
		$classes = "form-field {$this->field}-field {$this->class}";
		foreach ($extraClasses as $class) {
			$classes .= ' ' . $class;
		}

		$style = "--grid-area: {$this->name};";
		foreach ($extraStyles as $prop => $value) {
			$style .= $prop . ':' . $value . ';';
		}

		$attributes = [
			'class'     => $classes,
			'data-type' => $this->field,
			'style'     => $style,
		];

		if ($this->settings !== []) {
			$json = json_encode($this->settings);
			if ($json) {
				$attributes['data-settings'] = $json;
			}
		}

		$this->applyVisibility($attributes);

		return $attributes;
	}

	/**
	 * Apply visibility settings to form field attributes.
	 * This method can be called by child classes that override build().
	 *
	 * @param array<string,string> $attributes
	 */
	protected function applyVisibility(array &$attributes): void
	{
		if (!isset($this->settings['visibility'])) {
			return;
		}

		$visibility = $this->settings['visibility'];

		// Calculate initial visibility state for server-side rendering
		$isVisible           = $this->evaluateVisibility($visibility);
		$attributes['class'] = ($attributes['class'] ?? '') . ($isVisible ? ' field-visible' : ' field-hidden');

		// Add inline style to hide if needed
		if (!$isVisible) {
			$attributes['style'] = ($attributes['style'] ?? '') . ' display: none;';
		}
	}

	/**
	 * Evaluate visibility condition based on form data.
	 *
	 * @param array<string,mixed> $visibility
	 */
	protected function evaluateVisibility(array $visibility): bool
	{
		// Fields with visibility settings default to hidden
		// Get the field name to watch
		$watchField = $visibility['watch'] ?? '';
		if ($watchField === '') {
			return false; // No watch field specified, hide by default
		}

		// Get the expected value(s)
		$expectedValue = $visibility['value'] ?? null;
		if ($expectedValue === null) {
			return false; // No expected value, hide by default
		}

		// Get the operator (default to equality)
		$operator = $visibility['operator'] ?? '==';

		// Get the current value from the form
		$currentValue = $this->form->getFieldValue($watchField);

		// If we can't get the current value, hide the field by default
		// This ensures proper initial state for new forms
		if ($currentValue === null) {
			return false;
		}

		// Evaluate based on operator
		return $this->evaluateCondition($currentValue, $expectedValue, $operator);
	}

	/**
	 * Evaluate a visibility condition.
	 */
	protected function evaluateCondition(mixed $currentValue, mixed $expectedValue, string $operator): bool
	{
		// Handle array expected values (multiple possible values)
		if (is_array($expectedValue)) {
			foreach ($expectedValue as $value) {
				if ($this->evaluateCondition($currentValue, $value, $operator)) {
					return true; // Match found
				}
			}

			return false; // No matches found
		}

		// Handle array current values (checkboxes, multiselect, etc.)
		if (is_array($currentValue)) {
			return in_array($expectedValue, $currentValue, false);
		}

		// Evaluate based on operator
		// Note: Array $expectedValue is handled earlier (lines 184-191), so it's never an array here
		return match ($operator) {
			'=='    => $currentValue == $expectedValue,
			'!='    => $currentValue != $expectedValue,
			'>'     => is_numeric($currentValue) && is_numeric($expectedValue) && $currentValue > $expectedValue,
			'<'     => is_numeric($currentValue) && is_numeric($expectedValue) && $currentValue < $expectedValue,
			'>='    => is_numeric($currentValue) && is_numeric($expectedValue) && $currentValue >= $expectedValue,
			'<='    => is_numeric($currentValue) && is_numeric($expectedValue) && $currentValue <= $expectedValue,
			default => $currentValue == $expectedValue, // Default to equality
		};
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
			'maxlength'        => $this->maxlength > 0 ? (string)$this->maxlength : null,
			'pattern'          => $this->pattern === '' ? null : $this->pattern,
			'placeholder'      => $this->placeholder === '' ? null : $this->placeholder,
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
			'value'            => ($this->value === null || $this->value === '') ? null : $this->value,
			'min'              => is_null($this->min) ? null : (string)$this->min,
			'max'              => is_null($this->max) ? null : (string)$this->max,
			'step'             => is_null($this->step) ? null : (string)$this->step,
			'list'             => $this->datalist ? "datalist-{$this->uuid}" : null,
			'autocomplete'     => 'nofill',
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x): bool => !is_null($x));

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

	/**
	 * Build options for propertyOptions setting.
	 * Supports:
	 * - true: fetch unique values from current collection for this property
	 * - "collections": fetch unique category values from all collections
	 * - "schemas": fetch unique category values from all schemas.
	 *
	 * @return array<string>
	 */
	protected function buildOptionsForProperty(): array
	{
		$source = $this->settings['propertyOptions'] ?? true;

		if ($source === 'collections') {
			return $this->form->categoryListForCollections();
		}

		if ($source === 'schemas') {
			return $this->form->categoryListForSchemas();
		}

		if ($source === 'collectionIds') {
			return $this->form->collectionIdList();
		}

		// Default: fetch from current collection
		return $this->form->propertyListForCollection($this->name);
	}

	/**
	 * @return array<array<string,string>>
	 */
	protected function buildRelationalOptions(): array
	{
		$settings = $this->settings['relationalOptions'];
		if (!is_array($settings)) {
			return []; // Invalid settings, return empty array
		}

		$labelJoin     = $settings['join'] ?? ' ';
		$labelProperty = trim($settings['label'] ?? 'id');
		$valueProperty = trim($settings['value'] ?? 'id');
		$collection    = $settings['collection'] ?? '';
		$view          = $settings['view'] ?? '';

		if ($collection === '' && $view === '') {
			return []; // No data source specified, return empty array
		}

		// Split label property by spaces to support multiple properties
		$labelProperties = explode($labelJoin, $labelProperty);

		// Combine label properties with value property for fetching
		$propertiesToFetch = array_unique(array_merge($labelProperties, [$valueProperty]));

		// Extract include/exclude/sort filters from settings
		$filters = [];
		if (isset($settings['include'])) {
			$filters['include'] = $settings['include'];
		}
		if (isset($settings['exclude'])) {
			$filters['exclude'] = $settings['exclude'];
		}
		if (isset($settings['sort'])) {
			$filters['sort'] = $settings['sort'];
		}

		if ($view !== '') {
			$properties = $this->form->propertiesForView($propertiesToFetch, $view, $filters);
		} else {
			$properties = $this->form->propertiesForCollection($propertiesToFetch, $collection, $filters);
		}

		// Validate that properties is a list of arrays (not a wrapped structure like {"items": [...]})
		$source = $view !== '' ? "view '{$view}'" : "collection '{$collection}'";
		if ($properties === []) {
			return [];
		}
		if (!array_is_list($properties) || !is_array($properties[0])) {
			return [['value' => '', 'label' => "Error: {$source} returned invalid data for relationalOptions"]];
		}

		// Build the label from multiple properties if specified
		return array_map(function (array $o) use ($valueProperty, $labelProperties, $labelJoin): array {
			// If multiple label properties, concatenate them with spaces
			if (count($labelProperties) > 1) {
				$labelParts = array_map(fn (string $prop) => $o[$prop] ?? '', $labelProperties);
				$label      = implode($labelJoin, array_filter($labelParts)); // Filter out empty values
			} else {
				$label = $o[$labelProperties[0]] ?? '';
			}

			return ['value' => $o[$valueProperty], 'label' => $label];
		}, $properties);
	}

	/**
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 */
	protected function buildOptions(string $options = ''): string
	{
		// propertyOptions can be true (use current collection) or a string source ("collections", "schemas")
		$propertyOptions = $this->settings['propertyOptions'] ?? null;
		if ($propertyOptions === true || is_string($propertyOptions)) {
			$this->options = array_merge($this->options, $this->buildOptionsForProperty());
		}
		if (isset($this->settings['relationalOptions'])) {
			$this->options = array_merge($this->options, $this->buildRelationalOptions());
		}
		if (isset($this->settings['accessGroupOptions']) && $this->settings['accessGroupOptions'] === true) {
			$this->options = array_merge($this->options, $this->form->accessGroupOptionsForField());
		}
		if (is_array($this->value) && $this->value !== [] && !isset($this->settings['relationalOptions'])) {
			// Only merge values that aren't already represented in the options
			// to avoid duplicating predefined options (e.g. multicheckbox fields)
			$existingValues = [];
			foreach ($this->options as $key => $option) {
				if (is_array($option) && isset($option['value'])) {
					$existingValues[] = (string)$option['value'];
				} elseif (is_string($option)) {
					$existingValues[] = $option;
				}
				if (is_string($key) && is_array($option)) {
					// Grouped options: harvest nested values too
					foreach ($option as $groupedOption) {
						if (is_array($groupedOption) && isset($groupedOption['value'])) {
							$existingValues[] = (string)$groupedOption['value'];
						} elseif (is_string($groupedOption)) {
							$existingValues[] = $groupedOption;
						}
					}
				}
			}
			$newValues = array_values(array_diff(array_map(strval(...), $this->value), $existingValues));
			if ($newValues !== []) {
				$this->options = array_merge($newValues, $this->options); // value is first to maintain order
			}
		}

		if ($this->options !== []) {
			$this->options = self::deduplicateOptionsByValue($this->options);
		}

		if (($this->settings['sortOptions'] ?? false) === true) {
			sort($this->options);
		}

		$selected = is_array($this->value) ? $this->value : (string)($this->value ?? '');

		return $options . HTMLUtils::options($this->options, $selected);
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

	/**
	 * Remove duplicate options by their value, keeping the first occurrence.
	 * Works across mixed shapes (plain strings, {value,label} arrays) so that
	 * static options and derived options (propertyOptions, relationalOptions)
	 * don't produce duplicates when merged.
	 *
	 * Grouped options (string key => array of options) are passed through
	 * untouched since they represent <optgroup> structures.
	 *
	 * @param  array<mixed> $options
	 * @return array<mixed>
	 */
	protected static function deduplicateOptionsByValue(array $options): array
	{
		$seen   = [];
		$result = [];
		foreach ($options as $key => $option) {
			// Grouped options: preserve the group key and its children as-is
			if (is_string($key) && is_array($option) && !isset($option['value'])) {
				$result[$key] = $option;
				continue;
			}

			if (is_array($option) && isset($option['value'])) {
				$value = (string)$option['value'];
			} elseif (is_string($option) || is_numeric($option)) {
				$value = (string)$option;
			} else {
				$result[] = $option;
				continue;
			}

			if (in_array($value, $seen, true)) {
				continue;
			}
			$seen[]   = $value;
			$result[] = $option;
		}

		return $result;
	}

	protected function buildDatalist(): string
	{
		return HTMLUtils::element('datalist', $this->buildOptions(), ['id' => "datalist-{$this->uuid}"]);
	}
}
