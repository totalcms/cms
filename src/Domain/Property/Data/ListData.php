<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * List type property data.
 */
class ListData extends PropertyData
{
	/** @param array<string> $list */
	public function __construct(public array $list = [], public array $settings = [])
	{
		$this->list = self::repairList($list);
	}

	/**
	 * @param array<mixed> $list
	 *
	 * @return array<string>
	 * */
	private static function repairList(array $list): array
	{
		$list = array_filter($list);
		$list = array_unique($list);
		$list = array_values($list);
		$list = array_map('strval', $list);

		if (!self::verifyList($list)) {
			throw new \InvalidArgumentException('List must be a list:' . json_encode($list));
		}

		return $list;
	}

	/** @param array<mixed> $list */
	private static function verifyList(array $list): bool
	{
		if (!array_is_list($list)) {
			print_r($list);
			return false;
		}
		foreach ($list as $item) {
			if (!is_scalar($item)) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string> */
	public function transform(): array
	{
		return $this->list;
	}

	public function __toString(): string
	{
		return implode(',', $this->list);
	}
}
