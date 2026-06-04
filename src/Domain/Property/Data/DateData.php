<?php

namespace TotalCMS\Domain\Property\Data;

use Cake\Chronos\Chronos;
use TotalCMS\Support\Config;

/**
 * Date property data.
 */
class DateData extends PropertyData implements \Stringable
{
	public string $date;

	public const CREATION_DATE = 'onCreate';
	public const UPDATE_DATE   = 'onUpdate';

	public function __construct(string $date = '', public array $settings = [])
	{
		$this->date = $date === '' ? '' : self::cleanDate($date);
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		// If value is empty, use the default (e.g., "now")
		if (isset($default) && ($value === null || $value === '')) {
			return self::cleanDate((string)$default);
		}

		return self::cleanDate($value);
	}

	public static function cleanDate(?string $date = 'now', string $format = 'c'): string
	{
		if ($date === null || $date === '') {
			return '';
		}

		try {
			$config   = Config::init();
			$timezone = new \DateTimeZone($config->timezone);

			// Use Chronos for smart date parsing with natural language support
			$chronosDate = Chronos::parse($date, $timezone);

			return $chronosDate->format($format);
		} catch (\Exception) {
			// Fallback to empty string if parsing fails
			return '';
		}
	}

	/**
	 * Convert a UTC datetime string (e.g. a SQLite CURRENT_TIMESTAMP value) to
	 * the given timezone for display.
	 *
	 * Unlike cleanDate(), which parses the input AS the target timezone, this
	 * interprets the input as UTC and converts. Returns the input unchanged if
	 * it can't be parsed.
	 */
	public static function utcToTimezone(string $utcDate, string $timezone, string $format = 'Y-m-d H:i:s'): string
	{
		if ($utcDate === '') {
			return '';
		}

		try {
			return (new \DateTimeImmutable($utcDate, new \DateTimeZone('UTC')))
				->setTimezone(new \DateTimeZone($timezone !== '' ? $timezone : 'UTC'))
				->format($format);
		} catch (\Exception) {
			return $utcDate;
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
