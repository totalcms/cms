<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Property\Data\DateData;

class DateField extends FormField
{
	protected string $defaultInputType = 'date';
	protected string $defaultFieldType = 'date';
	protected string $dateFormat       = 'Y-m-d';

	public function init(): void
	{
		parent::init();

		if ((isset($this->settings[DateData::CREATION_DATE]) && $this->settings[DateData::CREATION_DATE] === true)
			 || (isset($this->settings[DateData::UPDATE_DATE]) && $this->settings[DateData::UPDATE_DATE] === true)) {
			$this->readonly = true;
		}

		// Handle smart defaults for date fields
		if (!empty($this->default) && empty($this->value)) {
			$this->value = DateData::cleanDate($this->default);
		}

		// Process existing value with smart parsing
		if (!empty($this->value)) {
			$this->value = DateData::cleanDate($this->value);
		}
	}
}
