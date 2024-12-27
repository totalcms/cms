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

	/** @param array<string,mixed> $settings */
	public function __construct(string $date, array $settings = [])
	{
		$this->date = empty($date) ? '' : self::cleanDate($date);
		$this->settings = $settings;
	}

	public static function defaultValue(mixed $value, mixed $default): mixed
	{
		return self::cleanDate($value);
	}

	public function actionsBeforeSave(): DateData
	{
		if (isset($this->settings[self::CREATION_DATE]) && $this->settings[self::CREATION_DATE] === true) {
			if ((empty($this->date) || $this->date === self::CREATION_DATE)) {
				$this->date = self::cleanDate();
			}
		} elseif (isset($this->settings[self::UPDATE_DATE]) && $this->settings[self::UPDATE_DATE] === true) {
			$this->date = self::cleanDate();
		}
		return $this;
	}

	private static function cleanDate(string $date = 'now'): string
	{
		$config   = Config::init();
		$timezone = new \DateTimeZone($config->timezone);
		$date     = new \DateTime($date, $timezone);

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
