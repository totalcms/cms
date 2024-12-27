<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Time type property data.
 */
class TimeData extends PropertyData
{
	public string $time;

	/** @param array<string,mixed> $settings */
	public function __construct(string $time = '', array $settings = [])
	{
		if (!empty($time) && !self::verifyTime($time)) {
			throw new \InvalidArgumentException('Invalid Time');
		}

		$this->time     = $time;
		$this->settings = $settings;
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
