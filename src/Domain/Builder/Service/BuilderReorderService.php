<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

/**
 * Coordinates a drag-drop reorder request: parse the JSON tree out of the
 * request payload, hand it to BuilderOrderService for reconciliation +
 * persistence, and return a result the HTTP layer can serialize.
 *
 * BuilderOrderService is the storage adapter (read/write the order file).
 * This service is the use-case wrapper that turns a raw POST body into a
 * write call and a count, so the action stays a thin HTTP shell.
 */
readonly class BuilderReorderService
{
	public function __construct(
		private BuilderConfigService $builderConfig,
		private BuilderOrderService $orderService,
	) {
	}

	/**
	 * Apply a reorder for the configured pages collection.
	 *
	 * @param  array<string,mixed>     $payload  the POST body (raw form fields)
	 *
	 * @return array{ok:bool,error?:string,count?:int} Result tuple. `ok=false`
	 *         indicates a client/server error and includes a user-facing
	 *         `error` message; the action maps it to an HTTP status.
	 */
	public function applyTree(array $payload): array
	{
		$tree = $this->parseTree($payload);

		if ($tree === null) {
			return ['ok' => false, 'error' => 'Missing or invalid tree'];
		}

		$collectionId = $this->builderConfig->getPagesCollectionId();

		try {
			$cleaned = $this->orderService->write($collectionId, $tree);
		} catch (\Throwable $e) {
			return ['ok' => false, 'error' => 'Reorder failed: ' . $e->getMessage()];
		}

		return ['ok' => true, 'count' => $this->countNodes($cleaned)];
	}

	/**
	 * Pull the tree JSON out of the POST body. Returns null on malformed
	 * input so the caller can return 422 with a clear message.
	 *
	 * @param  array<string,mixed>          $post
	 *
	 * @return list<array<string,mixed>>|null
	 */
	private function parseTree(array $post): ?array
	{
		$raw = $post['tree'] ?? null;
		if (!is_string($raw) || $raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return null;
		}

		/** @var list<array<string,mixed>> $tree */
		$tree = array_values(array_filter($decoded, is_array(...)));

		return $tree;
	}

	/**
	 * Recursively count nodes in a tree (for the response payload).
	 *
	 * @param array<int,mixed> $tree
	 */
	private function countNodes(array $tree): int
	{
		$count = 0;
		foreach ($tree as $node) {
			if (!is_array($node)) {
				continue;
			}
			$count++;
			$children = $node['children'] ?? [];
			if (is_array($children)) {
				$count += $this->countNodes($children);
			}
		}

		return $count;
	}
}
