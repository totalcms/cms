<?php

namespace TotalCMS\Domain\Property\Data;

use PhpParser\Builder\Property;
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
			$date = 'now';
		}

		// If the date is a timestamp, convert it to a formatted date string
		if (is_numeric($date)) {
			$date = date('Y-m-d H:i:s', intval($date));
		}

		try {
			$config   = Config::init();
			$timezone = new \DateTimeZone($config->timezone);
			$date     = new \DateTime($date, $timezone);
		} catch (\Exception $e) {
			return '';
		}

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
