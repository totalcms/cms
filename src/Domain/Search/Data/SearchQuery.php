<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Data;

/**
 * What a caller asks the search system for.
 *
 * `collection` null means cross-collection. `persona` gates draft/private
 * content the same way MCP personas do (public sees published only).
 * `locale` is forward-compat for 3.6 i18n; v1 providers may ignore it.
 *
 * `relevance` opts into term-coverage scoring (see ObjectSearcher::searchScored):
 * best-partial matches ranked best-first with OR recall. When false the provider
 * keeps its legacy AND-filter + positional scoring (byte-for-byte unchanged).
 */
final readonly class SearchQuery
{
	/**
	 * @param array<string,float> $weights Per-field relevance weights for
	 *        relevance mode (field name => weight). Empty = uniform 1.0.
	 */
	public function __construct(
		public string $text,
		public ?string $collection = null,
		public int $limit = 10,
		public int $offset = 0,
		public string $persona = 'public',
		public string $locale = '',
		public bool $relevance = false,
		public array $weights = [],
	) {
	}
}
