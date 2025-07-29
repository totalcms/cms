<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Deck type property data - a dictionary of named objects.
 */
class DeckData extends PropertyData
{
	/** @param array<string,array<string,mixed>> $deck */
	public function __construct(public array $deck = [], public array $settings = [])
	{
		if (!self::verifyDeck($deck)) {
			throw new \InvalidArgumentException('Deck must be a dictionary of named objects with scalar values');
		}
		$this->deck = $deck;
	}

	/** @param array<mixed> $deck */
	private static function verifyDeck(array $deck): bool
	{
		// Empty deck is valid
		if (empty($deck)) {
			return true;
		}

		// Must be associative array (dictionary), not indexed list
		if (array_is_list($deck)) {
			return false;
		}

		foreach ($deck as $name => $item) {
			// Verify name is valid identifier
			if (!is_string($name) || !preg_match('/^[a-zA-Z]\\w*$/', $name)) {
				return false;
			}

			// Each item must be an array (object)
			if (!is_array($item)) {
				return false;
			}

			// If item has an 'id' field, it must match the dictionary key
			if (isset($item['id']) && $item['id'] !== $name) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string,array<string,mixed>> */
	public function transform(): array
	{
		return $this->deck;
	}

	/**
	 * Get a specific deck item by name.
	 *
	 * @param string $name
	 *
	 * @return array<string,mixed>|null
	 */
	public function getItem(string $name): ?array
	{
		return $this->deck[$name] ?? null;
	}

	/**
	 * Set a deck item by name.
	 *
	 * @param string $name
	 * @param array<string,mixed> $item
	 */
	public function setItem(string $name, array $item): void
	{
		if (!preg_match('/^[a-zA-Z]\\w*$/', $name)) {
			throw new \InvalidArgumentException('Deck item name must be a valid identifier');
		}

		// If item has an 'id' field, it must match the dictionary key
		if (isset($item['id']) && $item['id'] !== $name) {
			throw new \InvalidArgumentException("Deck item 'id' field ('{$item['id']}') must match the dictionary key ('{$name}')");
		}

		$this->deck[$name] = $item;
	}

	/**
	 * Remove a deck item by name.
	 */
	public function removeItem(string $name): void
	{
		unset($this->deck[$name]);
	}

	/**
	 * Get all deck item names.
	 *
	 * @return array<string>
	 */
	public function getItemNames(): array
	{
		return array_keys($this->deck);
	}

	/**
	 * Check if deck has an item with the given name.
	 */
	public function hasItem(string $name): bool
	{
		return array_key_exists($name, $this->deck);
	}

	/**
	 * Get the count of items in the deck.
	 */
	public function count(): int
	{
		return count($this->deck);
	}
}
