<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Starter\Data;

/**
 * What `tcms starter:export` was asked to do.
 *
 * `includeContent` false is the structure-only export: every collection is
 * emptied except the Site Builder pages, so a site built for one client can
 * become a starter without carrying that client's content along.
 */
final readonly class StarterExportOptions
{
	public function __construct(
		public string $destination,
		public string $name,
		public string $description = '',
		public bool $includeContent = true,
		public bool $force = false,
	) {
	}
}
