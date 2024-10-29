<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Support\Config;

class DateField extends FormField
{
	protected string $defaultInputType = 'date';
	protected string $defaultFieldType = 'date';
	protected string $dateFormat       = 'Y-m-d';

	public function init(): void
	{
		parent::init();

		if (!empty($this->value)) {
			$config = Config::init();
			$timezone = new \DateTimeZone($config->timezone);

			$date = new \DateTime($this->value, $timezone);
			$date->setTimezone($timezone);

			$this->value = $date->format($this->dateFormat);
		}
		if ($this->default === 'onCreate' || $this->default === 'onUpdate') {
			$this->readonly = true;
		}
	}
}
