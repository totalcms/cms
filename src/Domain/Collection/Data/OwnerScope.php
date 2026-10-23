<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Data;

/**
 * The owner scope a non-admin's list read on an owned collection is limited
 * to: the items whose owner property equals the actor's id, or, for a list
 * owner property, contains it.
 *
 * The match is exact string equality — deliberately not the ObjectFilter
 * `include` grammar, which is case-insensitive, expands `*` wildcards, splits
 * on commas and coerces true/false. It is the only owner comparison:
 * CollectionAccessMiddleware::ownerAllows() and the admin form's owner check
 * use it too, so an object a member can see in a list is exactly an object
 * they can open.
 */
final readonly class OwnerScope
{
	public function __construct(
		public string $property,
		public string $value,
	) {
	}

	/**
	 * Whether the item's owner property holds exactly this scope's value: a
	 * scalar equal to it, or a list (several co-owners) with an entry equal to
	 * it. Each entry is compared exactly; nested arrays never match.
	 *
	 * @param array<string,mixed> $item
	 */
	public function matches(array $item): bool
	{
		$stored = $item[$this->property] ?? null;
		if (is_scalar($stored)) {
			return (string)$stored === $this->value;
		}
		if (!is_array($stored)) {
			return false;
		}
		foreach ($stored as $entry) {
			if (is_scalar($entry) && (string)$entry === $this->value) {
				return true;
			}
		}

		return false;
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
