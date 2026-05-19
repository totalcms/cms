<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Contract;

/**
 * A one-shot data/layout migration. Each implementation runs at most once
 * per install — success is recorded in the migration ledger so it never
 * runs again. Implementations must be idempotent in practice (re-running
 * a no-op'd migration should not throw).
 */
interface MigrationInterface
{
	/**
	 * Stable identifier used as the ledger key. Use kebab-case and never
	 * rename once a migration has shipped — renaming would cause already-
	 * applied migrations to run again on existing sites.
	 */
	public function id(): string;

	/**
	 * Short human-readable summary for logs and admin UI.
	 */
	public function description(): string;

	/**
	 * Perform the migration. Return value is recorded in the ledger and
	 * surfaced in logs; convention is "number of items migrated", but the
	 * meaning is migration-specific. Throw on unrecoverable failure — the
	 * runner records the error and retries on the next process.
	 */
	public function run(): int;
}
