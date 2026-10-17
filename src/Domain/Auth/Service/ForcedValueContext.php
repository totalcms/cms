<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

/**
 * Forced values for the objects the current request may write.
 *
 * A schema property with `protected: {"value": X}` is written as X on every
 * save by a non-admin. CollectionAccessMiddleware fills this context after it
 * has allowed a non-admin create, update or delete on a route that names a
 * `{collection}` and `{id}`, with what AuthFieldPolicy::enforce() forces on
 * that object. ObjectUpdater::updateObject() reads it and lays those values
 * over every save of that object, so deck items, file saves, uploads, meta
 * writes, property deletes and counters cannot leave an approved object
 * approved.
 *
 * It is request-scoped: one HTTP request per PHP process, and the container
 * holds a single instance. RequestScopeResetMiddleware clears it at the start
 * of every request, so a long-lived worker (FrankenPHP worker mode) never
 * carries one request's entries into the next. CLI commands, API keys, admin bearers and super
 * admins never reach the middleware's fill, so their writes are untouched.
 * Reading does not consume an entry: one request may save the same object
 * more than once and each save must carry the forced values.
 *
 * It layers on top of PrivilegedFieldGuard, which still applies forced values
 * on the body and property routes; applying them twice is idempotent. This
 * context catches every other path to storage.
 */
final class ForcedValueContext
{
	/** @var array<string,array<string,array<string,mixed>>> keyed [collection][id] */
	private array $values = [];

	/**
	 * @param array<string,mixed> $values forced values; an empty array is not stored
	 */
	public function remember(string $collection, string $id, array $values): void
	{
		if ($values === []) {
			return;
		}

		$this->values[$collection][$id] = $values;
	}

	/**
	 * The forced values remembered for this object, or [] when none were.
	 *
	 * @return array<string,mixed>
	 */
	public function forcedFor(string $collection, string $id): array
	{
		return $this->values[$collection][$id] ?? [];
	}

	/**
	 * Forget everything: for tests and long-running processes.
	 */
	public function clear(): void
	{
		$this->values = [];
	}
}
