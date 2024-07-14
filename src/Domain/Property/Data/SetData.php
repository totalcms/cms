<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class SetData extends PropertyData
{
	/** @param array<string> $set */
	public function __construct(public array $set)
	{
		if (!self::verifySet($set)) {
			throw new \InvalidArgumentException('Set must be a set of simple objects');
		}
		$this->set = $set;
	}

	/** @param array<mixed> $set */
	private static function verifySet(array $set): bool
	{
		if (!array_is_list($set)) {
			return false;
		}
		foreach ($set as $item) {
			if (!is_array($item)) {
				return false;
			}
			foreach ($item as $attribute) {
				if (!is_scalar($attribute)) {
					return false;
				}
			}
		}

		return true;
	}

	/** @return array<string> */
	public function transform(): array
	{
		return $this->set;
	}
}
