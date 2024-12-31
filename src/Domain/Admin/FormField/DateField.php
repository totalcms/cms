<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Support\Config;
use TotalCMS\Domain\Property\Data\DateData;

class DateField extends FormField
{
	protected string $defaultInputType = 'date';
	protected string $defaultFieldType = 'date';
	protected string $dateFormat       = 'Y-m-d';

	public function init(): void
	{
		parent::init();

		if ( (isset($this->settings[DateData::CREATION_DATE]) && $this->settings[DateData::CREATION_DATE] === true) ||
			 (isset($this->settings[DateData::UPDATE_DATE]) && $this->settings[DateData::UPDATE_DATE] === true)) {
				 $this->readonly = true;
		}

		if (!empty($this->value)) {
			$config = Config::init();
			$timezone = new \DateTimeZone($config->timezone);

			$date = new \DateTime($this->value, $timezone);
			$date->setTimezone($timezone);

			$this->value = $date->format('c');
		}
	}
}
