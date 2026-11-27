<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

/**
 * A products collection whose paid files can be fetched without buying, and
 * who can fetch them: anyone at all (no File Access Groups), or anyone who
 * signs in (the groups include `default`).
 */
final readonly class OpenDownloads
{
	public function __construct(
		public string $collection,
		public bool $anySignedIn,
	) {
	}
}
