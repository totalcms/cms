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

		if ($this->default === 'onCreate' || $this->default === 'onUpdate') {
			$this->readonly = true;

			// if the default value was set as the value, we need to set it to now
			if ($this->value === 'onCreate' || $this->value === 'onUpdate') {
				$this->value = 'now';
			}
			$this->default = '';
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
