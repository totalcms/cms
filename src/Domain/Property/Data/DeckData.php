<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * Deck type property data - a dictionary of named objects.
 */
class DeckData extends PropertyData implements \Stringable
{
	/** @var array<string|int,array<string,mixed>> */
	public array $deck;

	/** @param array<mixed> $deck */
	public function __construct(array $deck = [], public array $settings = [])
	{
		$error = $this->deckError($deck);
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}

		$this->deck = $deck;
	}

	/**
	 * Describe why a deck payload is invalid, or null when it is valid.
	 *
	 * Returns the specific broken rule rather than a generic "bad deck":
	 * these errors surface to operators writing JumpStart files and to AI
	 * agents authoring content, and "must be a dictionary of named objects"
	 * gives neither of them anything to act on when the real problem is a
	 * hyphen in a key.
	 *
	 * @param array<mixed> $deck
	 */
	private function deckError(array $deck): ?string
	{
		// Empty deck is valid (both empty associative array and empty indexed array)
		if ($deck === []) {
			return null;
		}

		// Must be associative array (dictionary), not indexed list
		if (array_is_list($deck)) {
			return 'Deck must be a dictionary of named items ({"item_name": {...}}), not a list. '
				. 'Each key names one item and matches that item\'s "id".';
		}

		foreach ($deck as $name => $item) {
			// Allow both string and int keys, validate the string representation
			$stringName = (string)$name;
			// Allow alphanumeric characters and underscores (no hyphens for Twig dot notation)
			if (!preg_match('/^\w+$/', $stringName)) {
				$suggestion = preg_replace('/[^a-zA-Z0-9_]+/', '_', $stringName);

				return sprintf(
					'Deck item name "%s" may only contain letters, numbers, and underscores — '
					. 'hyphens are not allowed because item names are read in Twig with dot '
					. 'notation (deck.%s). Use "%s" instead.',
					$stringName,
					$suggestion,
					$suggestion,
				);
			}

			// Each item must be an array (object)
			if (!is_array($item)) {
				return sprintf('Deck item "%s" must be an object of its own fields, %s given.', $stringName, get_debug_type($item));
			}

			// If item has an 'id' field, it must match the dictionary key (as string)
			if (isset($item['id']) && $item['id'] !== $stringName) {
				return sprintf(
					'Deck item "%s" has id "%s" — an item\'s "id" must match the key it is stored under.',
					$stringName,
					is_scalar($item['id']) ? (string)$item['id'] : get_debug_type($item['id']),
				);
			}
		}

		return null;
	}

	/** @return array<int|string,array<string,mixed>> */
	public function transform(): array
	{
		// Return empty array for empty deck (serializes as [] in JSON)
		// Schema now supports both empty arrays and non-empty objects
		return $this->deck;
	}

	/**
	 * Get a specific deck item by name.
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
	 * @param array<string,mixed> $item
	 */
	public function setItem(string $name, array $item): void
	{
		if (!preg_match('/^\w+$/', $name)) {
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

	public function __toString(): string
	{
		$data = $this->transform();

		$json = json_encode($data, JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return '';
		}

		return $json;
	}
}
