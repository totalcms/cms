<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\Index\Service\IndexReader;

/**
 * Resolves which DataViews must rebuild when a collection changes, and in
 * what order. Returns view IDs sorted so every view appears after all the
 * views it depends on (`viewDependencies`).
 */
readonly class DataViewDependencyResolver
{
	public function __construct(
		private IndexReader $indexReader,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<int,string> Ordered view IDs to rebuild for the collection.
	 */
	public function resolveForCollection(string $collection): array
	{
		try {
			$index = $this->indexReader->fetchIndex(DataViewData::COLLECTION_ID);
		} catch (\Throwable) {
			return [];
		}

		$views = [];
		foreach ($index->objects->toArray() as $view) {
			if (is_array($view)) {
				$views[] = $view;
			}
		}

		return $this->order($views, $collection);
	}

	/**
	 * Pure ordering core (no IO).
	 *
	 * @param array<int,array<string,mixed>> $views
	 *
	 * @return array<int,string>
	 */
	public function order(array $views, string $collection): array
	{
		$nodes = [];
		foreach ($views as $view) {
			$id = (string)($view['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$nodes[$id] = [
				'deps'     => $this->stringList($view['dependencies'] ?? []),
				'viewDeps' => $this->stringList($view['viewDependencies'] ?? []),
			];
		}

		// 1. Direct dependents of the changed collection.
		$affected = [];
		foreach ($nodes as $id => $node) {
			if (in_array($collection, $node['deps'], true)) {
				$affected[$id] = true;
			}
		}

		// 2. Downstream closure over viewDependencies.
		do {
			$added = false;
			foreach ($nodes as $id => $node) {
				if (isset($affected[$id])) {
					continue;
				}
				foreach ($node['viewDeps'] as $dep) {
					if (isset($affected[$dep])) {
						$affected[$id] = true;
						$added         = true;
						break;
					}
				}
			}
		} while ($added);

		if ($affected === []) {
			return [];
		}

		// 3. Topological sort (Kahn) within the affected set.
		$inDegree = [];
		foreach ($affected as $id => $_) {
			$inDegree[$id] = 0;
			foreach ($nodes[$id]['viewDeps'] as $dep) {
				if (isset($affected[$dep])) {
					$inDegree[$id]++;
				}
			}
		}

		$ordered = [];
		$queue   = [];
		foreach ($affected as $id => $_) {
			if ($inDegree[$id] === 0) {
				$queue[] = $id;
			}
		}

		while ($queue !== []) {
			$id        = array_shift($queue);
			$ordered[] = $id;
			foreach ($affected as $other => $_) {
				// $other is always in $affected, so $inDegree[$other] is always set.
				if (in_array($id, $nodes[$other]['viewDeps'], true)) {
					if (--$inDegree[$other] === 0) {
						$queue[] = $other;
					}
				}
			}
		}

		// 4. Cycle fallback: append any unresolved views in input order.
		if (count($ordered) < count($affected)) {
			$this->logger->warning('DataView dependency cycle detected; rebuilding in best-effort order.', [
				'collection' => $collection,
			]);
			foreach ($affected as $id => $_) {
				if (!in_array($id, $ordered, true)) {
					$ordered[] = $id;
				}
			}
		}

		return $ordered;
	}

	/**
	 * @param mixed $value
	 *
	 * @return array<int,string>
	 */
	private function stringList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_filter(array_map(
			static fn (mixed $v): string => is_string($v) ? $v : '',
			$value,
		), static fn (string $v): bool => $v !== ''));
	}
}
