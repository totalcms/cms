<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Data;

/**
 * The owner scope a non-admin's list read on an owned collection is limited
 * to: the items whose owner property equals the actor's id.
 *
 * The match is exact string equality — deliberately not the ObjectFilter
 * `include` grammar, which is case-insensitive, expands `*` wildcards, splits
 * on commas and coerces true/false. It is the same comparison as
 * CollectionAccessMiddleware::ownerAllows(), so an object a member can see in
 * a list is exactly an object they can open.
 */
final readonly class OwnerScope
{
	public function __construct(
		public string $property,
		public string $value,
	) {
	}

	/**
	 * Whether the item's owner property holds exactly this scope's value.
	 *
	 * @param array<string,mixed> $item
	 */
	public function matches(array $item): bool
	{
		return isset($item[$this->property])
			&& is_scalar($item[$this->property])
			&& (string)$item[$this->property] === $this->value;
	}

	/**
	 * The items this scope matches, in their original order, reindexed.
	 *
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function filter(array $items): array
	{
		return array_values(array_filter($items, $this->matches(...)));
	}

	/**
	 * A cache-key fragment unique to this property and value.
	 */
	public function cacheKey(): string
	{
		return md5($this->property . ':' . $this->value);
	}
}
