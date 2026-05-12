<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;

/**
 * Topological sort of extensions by their declared dependencies.
 *
 * Ensures extensions boot in the correct order: dependencies first.
 */
final class ExtensionDependencySorter
{
	/**
	 * Sort extension IDs so that dependencies come before dependents.
	 *
	 * @param array<string,ExtensionManifest> $manifests Keyed by extension ID
	 *
	 * @throws \RuntimeException If circular dependencies are detected
	 *
	 * @return list<string> Sorted extension IDs
	 */
	public function sort(array $manifests): array
	{
		$sorted   = [];
		$visiting = [];
		$visited  = [];

		foreach (array_keys($manifests) as $id) {
			if (!isset($visited[$id])) {
				$this->visit($id, $manifests, $sorted, $visiting, $visited);
			}
		}

		return $sorted;
	}

	/**
	 * @param array<string,ExtensionManifest> $manifests
	 * @param list<string>                    $sorted
	 * @param array<string,bool>              $visiting
	 * @param array<string,bool>              $visited
	 */
	private function visit(
		string $id,
		array $manifests,
		array &$sorted,
		array &$visiting,
		array &$visited,
	): void {
		if (isset($visiting[$id])) {
			throw new \RuntimeException("Circular dependency detected involving extension '{$id}'");
		}

		if (isset($visited[$id])) {
			return;
		}

		$visiting[$id] = true;

		if (isset($manifests[$id])) {
			foreach ($manifests[$id]->requiredExtensions() as $depId => $constraint) {
				if (isset($manifests[$depId])) {
					$this->visit($depId, $manifests, $sorted, $visiting, $visited);
				}
			}
		}

		unset($visiting[$id]);
		$visited[$id] = true;
		$sorted[]     = $id;
	}
}
