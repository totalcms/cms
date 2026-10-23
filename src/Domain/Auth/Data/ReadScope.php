<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Data;

use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Collection\Data\OwnerScope;

/**
 * Which objects of a collection an actor may read, from
 * {@see AccessGuard::readScope()}.
 *
 * An object is visible when the actor owns it ({@see $owner}, drafts
 * included), or else when it is published and {@see $published} allows
 * others' published objects, or a draft and {@see $drafts} allows others'
 * drafts. An object is a draft when its `draft` field is true (or `1`,
 * `"1"`, `"true"` from an import); anything else is published.
 *
 *   - everything: trusted actors, and a group grant on an unowned collection;
 *   - own + published: a member on an owned collection that is publicly
 *     readable, so signing in never shows less than an anonymous visitor sees;
 *   - own only: a member on an owned collection that is not;
 *   - published only, or nothing: everyone without a grant.
 */
final readonly class ReadScope
{
	public function __construct(
		public ?OwnerScope $owner = null,
		public bool $published = true,
		public bool $drafts = true,
	) {
	}

	public static function all(): self
	{
		return new self();
	}

	public static function publishedOnly(): self
	{
		return new self(published: true, drafts: false);
	}

	public static function none(): self
	{
		return new self(published: false, drafts: false);
	}

	/**
	 * Whether the scope removes nothing, so a caller can skip filtering.
	 */
	public function isAll(): bool
	{
		return !$this->owner instanceof OwnerScope && $this->published && $this->drafts;
	}

	/**
	 * Whether any draft can be visible: everyone's, or the actor's own.
	 */
	public function seesDrafts(): bool
	{
		return $this->drafts || $this->owner instanceof OwnerScope;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function matches(array $item): bool
	{
		if ($this->owner?->matches($item) === true) {
			return true;
		}

		return self::isDraft($item) ? $this->drafts : $this->published;
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
		return $this->isAll() ? array_values($items) : array_values(array_filter($items, $this->matches(...)));
	}

	/**
	 * A cache-key fragment unique to this scope.
	 */
	public function cacheKey(): string
	{
		return md5(($this->owner?->cacheKey() ?? '-') . ':' . (int)$this->published . (int)$this->drafts);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public static function isDraft(array $item): bool
	{
		return in_array($item['draft'] ?? false, [true, 1, '1', 'true'], true);
	}
}
