<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Data;

/**
 * One section of the Share Cards utility: the Site Builder pages, or a
 * collection the Site Builder serves. `$settingsUrl` is the admin page that
 * holds the overlay these cards draw (relative: the admin has a base href).
 */
final readonly class ShareCardSection
{
	public const PAGES = 'pages';
	public const COLLECTION = 'collection';

	public function __construct(
		public string $id,
		public string $title,
		public string $kind,
		public int $count,
		public string $settingsUrl,
	) {
	}
}
