<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Time type property data.
 */
class TimeData extends PropertyData
{
	public string $time;

	public function __construct(string $time = '', public array $settings = [])
	{
		if (!empty($time) && !self::verifyTime($time)) {
			throw new \InvalidArgumentException('Invalid Time');
		}

		$this->time = $time;
	}

	private static function verifyTime(string $time): bool
	{
		$strtotime = strtotime($time);

		return $time === date('H:i', $strtotime ?: 0);
	}

	public function transform(): string
	{
		return (string)$this;
	}

	public function __toString(): string
	{
		return $this->time;
	}
}
