<?php

namespace TotalCMS\Domain\Property\Data;

use TotalCMS\Support\Config;

/**
 * Date property data.
 */
class DateData extends PropertyData
{
	public string $date;

	public const CREATION_DATE = 'onCreate';
	public const UPDATE_DATE   = 'onUpdate';

	public function __construct(string $date)
	{
		$this->date   = empty($date) ? '' : self::cleanDate($date);
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		if (isset($default)) {
			if ((empty($value) || $value === self::CREATION_DATE) && $default === self::CREATION_DATE) {
				$value = self::cleanDate();
			} elseif ($default === self::UPDATE_DATE) {
				$value = self::cleanDate();
			}
		}

		return self::cleanDate($value);
	}

	private static function cleanDate(string $date = 'now'): string
	{
		$config = Config::init();
		$timezone = new \DateTimeZone($config->timezone);

		$date = new \DateTime($date);
		$date->setTimezone($timezone);

		return $date->format('c');
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
