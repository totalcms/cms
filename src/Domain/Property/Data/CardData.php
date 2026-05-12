<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * Card type property data — a single named object with schema-defined properties.
 *
 * A card is functionally a single-instance deck: same nested-property storage
 * model, but with a fixed cardinality of one. Useful for grouping related settings
 * (e.g. sitemap config, auth options) into a nested object on a parent schema.
 */
class CardData extends PropertyData implements \Stringable
{
	/** @var array<string,mixed> */
	public array $card;

	/**
	 * @param array<mixed>        $card
	 * @param array<string,mixed> $settings
	 */
	public function __construct(array $card = [], public array $settings = [])
	{
		if (!$this->verifyCard($card)) {
			throw new \InvalidArgumentException('Card must be a named-property object (associative array)');
		}

		/** @var array<string,mixed> $card */
		$this->card = $card;
	}

	/** @param array<mixed> $card */
	private function verifyCard(array $card): bool
	{
		// Empty card is valid
		if ($card === []) {
			return true;
		}

		// Must be associative array (named-property object), not indexed list
		if (array_is_list($card)) {
			return false;
		}

		foreach (array_keys($card) as $name) {
			$stringName = (string)$name;
			// Same naming rules as deck items: alphanumeric + underscore for Twig dot notation
			if (!preg_match('/^\w+$/', $stringName)) {
				return false;
			}
		}

		return true;
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return $this->card;
	}

	public function get(string $name): mixed
	{
		return $this->card[$name] ?? null;
	}

	public function set(string $name, mixed $value): void
	{
		if (!preg_match('/^\w+$/', $name)) {
			throw new \InvalidArgumentException('Card property name must contain only alphanumeric characters and underscores');
		}

		$this->card[$name] = $value;
	}

	public function has(string $name): bool
	{
		return array_key_exists($name, $this->card);
	}

	public function isEmpty(): bool
	{
		return $this->card === [];
	}

	public function __toString(): string
	{
		$json = json_encode($this->card, JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return '';
		}

		return $json;
	}
}
