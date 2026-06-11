<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Compat;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\ObjectTitleResolver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchServiceInterface;

/**
 * `search(query)` — ChatGPT-compatibility tool. ChatGPT's connector requires a
 * tool named exactly `search` returning {results:[{id,title,url}]}; this is the
 * adapter over T3's cross-collection search. Reuses the SearchCollectionsTool
 * path (persona filter inside the provider → drafts never leak to anonymous
 * callers). The `id` is a composite "{collection}:{objectId}" the `fetch` tool
 * decodes. Exempt from mcp.toolPrefix so the literal name survives.
 */
readonly class SearchTool
{
	private const DEFAULT_LIMIT = 20;

	public function __construct(
		private SearchServiceInterface $searchService,
		private CollectionRepository $collections,
		private ObjectFetcher $objectFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
		private McpSchemaResolver $schemaResolver,
		private ObjectTitleResolver $titleResolver,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'search',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Search',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			outputSchema: $this->outputSchema(),
			exemptFromPrefix: true,
		));
	}

	/**
	 * @return array{results: list<array{id: string, title: string, url: string}>}
	 */
	public function handler(string $query): array
	{
		if (trim($query) === '') {
			throw new ToolCallException('A non-empty `query` is required.');
		}

		$persona = $this->personaContext->current();

		$visible = array_filter(
			$this->collections->listAllCollections(),
			fn (CollectionData $c): bool => $this->schemaResolver->isAccessibleTo($c, $persona->value),
		);

		$results = [];

		foreach ($visible as $collection) {
			$hits = $this->searchService->search(new SearchQuery(
				text: $query,
				collection: $collection->id,
				limit: self::DEFAULT_LIMIT,
				persona: $persona->value,
				relevance: true,
			));

			if ($hits === []) {
				continue;
			}

			$titleProperty = $this->schemaResolver->forCollection($collection)['titleProperty'];
			$nonExposed    = $this->schemaResolver->nonExposedProperties($collection);

			foreach ($hits as $hit) {
				if (!$this->objectFetcher->existsObject($collection->id, $hit->id)) {
					continue;
				}
				$object = $this->objectFetcher->fetchObject($collection->id, $hit->id)->toArray();
				foreach ($nonExposed as $field) {
					unset($object[$field]);
				}

				$results[] = [
					'id'    => CompositeObjectId::encode($collection->id, $hit->id),
					'title' => $this->titleResolver->resolve($object, $titleProperty),
					'url'   => $this->urlBuilder->buildUrl($collection, $object),
				];

				if (count($results) >= self::DEFAULT_LIMIT) {
					break 2;
				}
			}
		}

		return ['results' => $results];
	}

	private function description(): string
	{
		return implode(' ', [
			'Search site content across all collections you can see.',
			'Returns matching results as {id, title, url}; pass a result id to `fetch` to read the full document.',
			'Drafts are hidden from anonymous callers.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['query'],
			'additionalProperties' => false,
			'properties'           => [
				'query' => [
					'type'        => 'string',
					'description' => 'Search terms.',
				],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function outputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['results'],
			'properties' => [
				'results' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'required'             => ['id', 'title', 'url'],
						'additionalProperties' => false,
						'properties'           => [
							'id'    => ['type' => 'string'],
							'title' => ['type' => 'string'],
							'url'   => ['type' => 'string'],
						],
					],
				],
			],
		];
	}
}
