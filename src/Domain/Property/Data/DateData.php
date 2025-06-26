<?php

namespace TotalCMS\Domain\Property\Data;

use Cake\Chronos\Chronos;
use TotalCMS\Support\Config;

/**
 * Date property data.
 */
class DateData extends PropertyData
{
	public string $date;

	public const CREATION_DATE = 'onCreate';
	public const UPDATE_DATE   = 'onUpdate';

	public function __construct(string $date = '', public array $settings = [])
	{
		$this->date = empty($date) ? '' : self::cleanDate($date);
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		return self::cleanDate($value);
	}

	public static function cleanDate(?string $date = 'now'): string
	{
		if (empty($date)) {
			return '';
		}

		try {
			$config   = Config::init();
			$timezone = new \DateTimeZone($config->timezone);

			// Use Chronos for smart date parsing with natural language support
			$chronosDate = Chronos::parse($date, $timezone);

			return $chronosDate->format('c');
		} catch (\Exception $e) {
			// Fallback to empty string if parsing fails
			return '';
		}
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->date;
	}
}
