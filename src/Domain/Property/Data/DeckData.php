<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class DeckData extends PropertyData
{
	/** @param array<string> $deck */
	public function __construct(public array $deck = [])
	{
		if (!self::verifyDeck($deck)) {
			throw new \InvalidArgumentException('Deck must be a set of simple objects');
		}
		$this->deck = $deck;
	}

	/** @param array<mixed> $deck */
	private static function verifyDeck(array $deck): bool
	{
		if (!array_is_list($deck)) {
			return false;
		}
		foreach ($deck as $item) {
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
		return $this->deck;
	}
}
