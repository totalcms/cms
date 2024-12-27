<?php

namespace TotalCMS\Domain\Property\Data;

use Illuminate\Support\Arr;

/**
 * String type property data.
 */
class DeckData extends PropertyData
{
	/**
	 * @param array<string> $deck
	 * @param array<string,mixed> $settings
	 */
	public function __construct(public array $deck = [], array $settings = [])
	{
		$this->settings = $settings;
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
