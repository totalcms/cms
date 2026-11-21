<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Lifecycle;

/** What one prune run found and did. */
final readonly class PruneReport
{
	/**
	 * @param int          $listed     files under the prefix
	 * @param int          $referenced keys that objects point at
	 * @param int          $orphans    old files nothing points at
	 * @param int          $deleted    orphans removed in this run
	 * @param int          $bytes      size of the orphans: reclaimed when deleting, reclaimable in a dry run
	 * @param list<string> $sample     the first 50 orphan keys
	 * @param bool         $capped     more orphans remain than this run was allowed to delete
	 * @param bool         $downgraded delete was asked for, but something could not be read, so nothing was deleted
	 * @param list<string> $problems   what could not be read
	 * @param bool         $refused    no prefix is set, so nothing was listed
	 */
	public function __construct(
		public int $listed = 0,
		public int $referenced = 0,
		public int $orphans = 0,
		public int $deleted = 0,
		public int $bytes = 0,
		public array $sample = [],
		public bool $capped = false,
		public bool $downgraded = false,
		public array $problems = [],
		public bool $refused = false,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return get_object_vars($this);
	}
}
