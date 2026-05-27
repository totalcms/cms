<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Data;

/**
 * What a caller asks the search system for.
 *
 * `collection` null means cross-collection. `persona` gates draft/private
 * content the same way MCP personas do (public sees published only).
 * `locale` is forward-compat for 3.6 i18n; v1 providers may ignore it.
 */
final readonly class SearchQuery
{
	public function __construct(
		public string $text,
		public ?string $collection = null,
		public int $limit = 10,
		public int $offset = 0,
		public string $persona = 'public',
		public string $locale = '',
	) {
	}
}
