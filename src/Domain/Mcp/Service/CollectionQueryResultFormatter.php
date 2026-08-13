<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Query\Data\QueryResult;

/**
 * Single source of truth for the collection-query result contract shared by
 * `query_collection` (QueryCollectionTool) and every schema-defined
 * saved-query tool (SavedQueryTool / SavedQueryToolFactory).
 *
 * **Why this exists.** A saved-query tool is `query_collection` with
 * filters/sort/limit baked in at definition time — same underlying
 * `IndexQueryService::query()` call, same `QueryResult` DTO back. Before this
 * class, the two tools built their result envelopes independently and had
 * drifted: `query_collection` returned `{items, total, limit, offset,
 * has_more}` while saved-query tools discarded everything QueryResult
 * offered except `items` and returned `{items, count}` — leaving a caller
 * unable to tell a complete result from the tip of a truncated one. The
 * `items`-element JSON Schema was also duplicated character-for-character
 * between the two tools' outputSchema builders, guaranteed to drift further.
 *
 * **Two responsibilities, nothing else:**
 *   1. `envelope()` — build the runtime result array from a `QueryResult`
 *      plus the caller's already-processed items (stripped of non-exposed
 *      fields, content-rendered, URL-decorated — this class does none of
 *      that; it only assembles the envelope).
 *   2. `outputSchema()` — the JSON Schema for that envelope, parameterized on
 *      the `items` array's description so each tool can keep its own flavor
 *      of that text (e.g. the saved-query variant names the bound
 *      collection) while the rest of the schema — including the per-item
 *      element shape — is structurally identical and cannot drift.
 */
readonly class CollectionQueryResultFormatter
{
	/**
	 * @param  list<array<string,mixed>> $items Already-processed items — this method does not read $result->items.
	 *
	 * @return array{items: list<array<string,mixed>>, total: int, limit: int, offset: int, has_more: bool}
	 */
	public function envelope(QueryResult $result, array $items): array
	{
		return [
			'items'    => $items,
			'total'    => $result->total,
			'limit'    => $result->limit,
			'offset'   => $result->offset,
			'has_more' => $result->hasMore(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function outputSchema(string $itemsDescription): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['items', 'total', 'limit', 'offset', 'has_more'],
			'additionalProperties' => false,
			'properties'           => [
				'items' => [
					'type'        => 'array',
					'description' => $itemsDescription,
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'required'             => ['id'],
						'properties'           => [
							'id'  => ['type' => 'string'],
							'url' => ['type' => 'string', 'description' => 'Public URL for this object when the collection has a URL pattern configured.'],
						],
					],
				],
				'total'    => ['type' => 'integer', 'description' => 'Total matching items (post-persona-filter).'],
				'limit'    => ['type' => 'integer', 'description' => 'Cap applied to this response — server may have lowered the requested limit.'],
				'offset'   => ['type' => 'integer'],
				'has_more' => ['type' => 'boolean', 'description' => 'True when total > offset + count(items); call again with offset += limit to paginate.'],
			],
		];
	}
}
