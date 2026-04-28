<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class RadioField extends ChoiceField
{
	protected string $defaultInputType = 'radio';
	protected string $defaultFieldType = 'radio';

	protected const INPUT_TYPE           = 'radio';
	protected const OPTION_CLASS         = 'radio';
	protected const LABEL_CLASS          = 'radio-label';
	protected const GROUP_FIELDSET_CLASS = 'radio-group-fieldset';
	protected const GROUP_LEGEND_CLASS   = 'radio-group-legend';
	protected const REQUIRED_ON_OPTION   = true;

	protected function isOptionSelected(string $optionValue): bool
	{
		return (string)$this->value === $optionValue;
	}
}
