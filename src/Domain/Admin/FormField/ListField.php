<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class ListField extends MultiselectField
{
	protected string $defaultInputType = 'select';
	protected string $defaultFieldType = 'list';

	public function init(): void
	{
		parent::init();

		$this->rows = 1;
	}

	protected function buildOptions(string $options = ''): string
	{
		parent::buildOptions($options);

		// Grouped options (string key => array of options, rendered as
		// <optgroup>s) are left in group order: hoisting the selected entries
		// out of their groups would break the groups, and the old reorder saw
		// no `value` key on a group and dropped every one of them — the podcast
		// show's Categories picker came up empty for any show that had saved
		// categories. Selection state still renders inside the groups.
		if ($this->value !== [] && !self::hasGroupedOptions($this->options)) {
			// Reorder options to put selected values first, maintaining their order from $this->value
			$valueOptions     = [];
			$remainingOptions = [];

			// Parse $this->value - it could be array or JSON string
			$selectedValues = is_string($this->value) ? json_decode($this->value, true) : $this->value;
			if (!is_array($selectedValues)) {
				$selectedValues = [];
			}

			// Create a map of option values to options for quick lookup
			$optionsMap = [];
			foreach ($this->options as $option) {
				if (is_string($option)) {
					$optionsMap[$option] = $this->optionFromString($option);
				} elseif (is_array($option) && isset($option['value'])) {
					$optionsMap[$option['value']] = $option;
				}
			}

			// First, add options in the exact order they appear in $this->value
			foreach ($selectedValues as $selectedValue) {
				if (isset($optionsMap[$selectedValue])) {
					$valueOptions[] = $optionsMap[$selectedValue];
					// Remove from map so we don't add it again
					unset($optionsMap[$selectedValue]);
				}
			}

			// Add remaining options that weren't in $this->value
			$remainingOptions = array_values($optionsMap);

			// Rebuild $this->options with selected values first, then remaining options
			$this->options = array_merge($valueOptions, $remainingOptions);
		}

		$selected = is_array($this->value) ? $this->value : (string)($this->value ?? '');

		return $options . HTMLUtils::options($this->options, $selected);
	}

	/** @param array<mixed> $options */
	private static function hasGroupedOptions(array $options): bool
	{
		foreach ($options as $key => $option) {
			if (is_string($key) && is_array($option) && !isset($option['value'])) {
				return true;
			}
		}

		return false;
	}
}
