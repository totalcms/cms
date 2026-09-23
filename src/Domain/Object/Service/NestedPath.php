<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

/**
 * Addressing a property nested inside another: `obj[parent][path]` for a card
 * child (one segment), `obj[parent][itemId][child]` for a deck child
 * (`"itemId/child"`). Shared by ObjectUpdater (replace the leaf) and
 * ObjectPatcher (merge into it).
 */
final class NestedPath
{
	/**
	 * A reference to the leaf slot, creating the parent and every intermediate
	 * container on the way. Siblings at every level are left alone.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function &slot(array &$data, string $parent, string $path): mixed
	{
		$segments = $path === '' ? [] : explode('/', $path);
		$leaf     = (string)array_pop($segments);

		$cursor = &$data[$parent];
		if (!is_array($cursor)) {
			$cursor = [];
		}
		foreach ($segments as $segment) {
			if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
				$cursor[$segment] = [];
			}
			$cursor = &$cursor[$segment];
		}

		return $cursor[$leaf];
	}
}
