<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Content;

use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;

/**
 * Phase 0 placeholder — hardcoded query against the `blog` collection.
 *
 * Proves the QueryPipeline integration end-to-end before Phase 1 generalizes
 * it. Phase 1's ToolGenerator replaces this with auto-generated query_<collection>
 * tools per schema. Once the generator lands, this class is deleted.
 *
 * Marked `access: public` so the Phase 0 verification flow can exercise the
 * anonymous persona without changing schema config.
 */
readonly class HardcodedBlogQueryTool
{
	private const COLLECTION = 'blog';

	public function __construct(
		private IndexQueryService $indexQueryService,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'query_blog',
			description: 'Query the blog collection. Returns paginated blog post index entries with id, title, and standard metadata fields. Phase 0 placeholder — Phase 1 generates this dynamically per collection.',
			access: 'public',
			handler: $this->handler(...),
			inputSchema: [
				'type'       => 'object',
				'properties' => [
					'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10, 'description' => 'Maximum items to return.'],
					'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Number of items to skip (for pagination).'],
				],
				'additionalProperties' => false,
			],
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(int $limit = 10, int $offset = 0): array
	{
		$result = $this->indexQueryService->query(self::COLLECTION, [
			'limit'  => (string)$limit,
			'offset' => (string)$offset,
		]);

		return [
			'items'    => $result->items,
			'total'    => $result->total,
			'limit'    => $result->limit,
			'offset'   => $result->offset,
			'has_more' => $result->hasMore(),
		];
	}
}
