<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class ChecklistField extends ChoiceField
{
	protected string $defaultInputType = 'checkbox';
	protected string $defaultFieldType = 'checklist';

	protected const INPUT_TYPE             = 'checkbox';
	protected const OPTION_CLASS           = 'checkbox';
	protected const LABEL_CLASS            = 'checkbox-label';
	protected const GROUP_FIELDSET_CLASS   = 'checklist-group';
	protected const GROUP_LEGEND_CLASS     = 'checkbox-group-legend';
	protected const INPUT_CLASS            = 'checkbox';
	protected const REQUIRED_ON_CONTAINER  = true;
	protected const SUPPORTS_TOGGLE_ALL    = true;

	protected function isOptionSelected(string $optionValue): bool
	{
		$currentValue = $this->getValue();

		if (is_array($currentValue)) {
			return in_array($optionValue, $currentValue, true);
		}

		return (string)$currentValue === $optionValue;
	}

	/**
	 * Normalize empty/missing values to an empty array so array-shaped checks work.
	 */
	public function getValue(): mixed
	{
		$value = parent::getValue();

		if ($value === null || $value === '') {
			return [];
		}

		return $value;
	}
}
