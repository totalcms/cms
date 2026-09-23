<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

/**
 * What a record batch import did: how many records were written (or queued)
 * and which were skipped, each with its offset in the source, its id when it
 * carried one, and the reason.
 */
final readonly class ImportBatchResult
{
	/**
	 * @param list<array{offset: int|string, id: string|null, reason: string}> $skipped
	 */
	public function __construct(
		public int $imported,
		public array $skipped,
	) {
	}
}
