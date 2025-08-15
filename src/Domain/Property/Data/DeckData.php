<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Deck type property data - a dictionary of named objects.
 */
class DeckData extends PropertyData
{
	/** @var array<string|int,array<string,mixed>> */
	public array $deck;

	/** @param array<mixed> $deck */
	public function __construct(array $deck = [], public array $settings = [])
	{
		// Call parent constructor with empty id (decks don't have meaningful IDs for CSV export)
		parent::__construct('', $settings);
		
		// Validate the deck (accepts both string and int keys)
		if (!self::verifyDeck($deck)) {
			throw new \InvalidArgumentException('Deck must be a dictionary of named objects');
		}

		$this->deck = $deck;
	}

	/** @param array<mixed> $deck */
	private static function verifyDeck(array $deck): bool
	{
		// Empty deck is valid (both empty associative array and empty indexed array)
		if (empty($deck)) {
			return true;
		}

		// Must be associative array (dictionary), not indexed list
		if (array_is_list($deck)) {
			return false;
		}

		foreach ($deck as $name => $item) {
			// Allow both string and int keys, validate the string representation
			$stringName = (string)$name;
			// Allow alphanumeric characters and underscores
			if (!preg_match('/^[0-9a-zA-Z_]+$/', $stringName)) {
				return false;
			}

			// Each item must be an array (object)
			if (!is_array($item)) {
				return false;
			}

			// If item has an 'id' field, it must match the dictionary key (as string)
			if (isset($item['id']) && $item['id'] !== $stringName) {
				return false;
			}
		}

		return true;
	}

	/** @return array<int|string,array<string,mixed>>|object */
	public function transform(): array|object
	{
		// Return empty object (stdClass) for empty deck to ensure JSON serialization as {}
		// This prevents empty decks from being serialized as [] which fails schema validation
		if (empty($this->deck)) {
			return new \stdClass();
		}

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
		if (!preg_match('/^[0-9a-zA-Z_]+$/', $name)) {
			throw new \InvalidArgumentException('Deck item name must contain only alphanumeric characters and underscores');
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
	 * @return array<int|string>
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

	/**
	 * Convert deck data to string for CSV export.
	 * Deck data is too complex for CSV format, so return empty string to skip export.
	 * Use JSON import/export for deck data instead.
	 */
	public function __toString(): string
	{
		// Deck data is too complex for reliable CSV export/import
		// Return empty string so the field gets skipped in CSV
		return '';
	}
}
