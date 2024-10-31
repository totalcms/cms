<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * List type property data.
 */
class ListData extends PropertyData
{
	/** @param array<string> $list */
	public function __construct(public array $list = [])
	{
		if (!self::verifyList($list)) {
			throw new \InvalidArgumentException('List must be a list:' . json_encode($list));
		}
		$this->list = array_unique($list);
	}

	/** @param array<string> $list */
	private static function verifyList(array $list): bool
	{
		if (!array_is_list($list)) {
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
}
