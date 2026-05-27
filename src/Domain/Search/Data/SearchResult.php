<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Search\Data;

/**
 * One ranked hit from a search provider.
 *
 * `score` is normalised to 0-1 where 1 = best match. Providers' internal
 * scores should be linearly rescaled to this range; SearchService relies
 * on the normalisation for cross-provider behaviour (e.g., scoring
 * fallback results from TextSearchProvider against a semantic provider's
 * results would be incoherent otherwise).
 *
 * `snippet` is optional HTML/text excerpt with `<em>` highlighting. Providers
 * that don't support highlighting return null.
 */
final readonly class SearchResult
{
	public function __construct(
		public string $collection,
		public string $id,
		public float $score,
		public ?string $snippet = null,
	) {
	}
}
