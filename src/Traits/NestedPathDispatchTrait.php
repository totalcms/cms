<?php

declare(strict_types=1);

namespace TotalCMS\Traits;

use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Shared dispatch logic for actions whose route shape is now used for both
 * legacy flat operations (gallery file delete, depot meta update, etc.) AND
 * card-nested property operations.
 *
 * The Phase 2 routes use `{path:.+}` as a greedy segment that captures either:
 *   - a single filename (legacy gallery / depot file path), or
 *   - a child key inside a card-nested property (e.g. `mycard.image`'s `image`).
 *
 * `classifyDispatchPath()` returns the sanitized path and whether it points at
 * a real on-disk directory under the parent property — the dispatch signal
 * actions use to choose between nested behavior and the legacy flat flow.
 */
trait NestedPathDispatchTrait
{
	/**
	 * @param array<string,string> $args
	 *
	 * @return array{path: string, nested: bool}
	 */
	protected function classifyDispatchPath(array $args, FileFetcher $fileFetcher): array
	{
		$raw  = $args['path'] ?? $args['name'] ?? '';
		$path = PathUtils::sanitizeSubpath($raw);

		$nested = $fileFetcher->isNestedDirectory(
			$args['collection'],
			$args['id'],
			$args['property'],
			$path,
		);

		return ['path' => $path, 'nested' => $nested];
	}
}
