<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\SavedQuery;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;

/**
 * Runtime tool for a single schema-defined entry.
 *
 * Handler flow:
 *   1. Persona check (collection.mcp.access vs current persona).
 *   2. Resolve {{params.X}} placeholders in filters via FilterValueResolver.
 *   3. Build REST-style include/exclude strings + persona-aware safety filters.
 *   4. Query via IndexQueryService.
 *   5. Strip non-exposed fields, render content via ContentRenderer,
 *      decorate items with URLs.
 *   6. Return MCP tool result envelope.
 *
 * Errors return `isError: true` with a recovery hint — never throws past
 * the SDK transport.
 */
final readonly class SavedQueryTool
{
	public function __construct(
		public SavedQueryToolDefinition $definition,
		private IndexQueryService $indexQueryService,
		private FilterValueResolver $filterValueResolver,
		private ContentRenderer $contentRenderer,
		private PersonaContext $personaContext,
		private ObjectUrlBuilder $objectUrlBuilder,
		private McpSchemaResolver $schemaResolver,
		private CollectionRepository $collectionRepository,
	) {
	}

	/**
	 * @param  array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function handle(array $args): array
	{
		try {
			$persona = $this->personaContext->current();

			if (!$this->personaCanAccess($persona)) {
				return $this->errorResult(
					'This tool requires admin access. The current connection is anonymous.',
				);
			}

			$collection = $this->collectionRepository->fetchCollection($this->definition->collectionName);
			if ($collection === null) {
				return $this->errorResult(sprintf(
					'Collection "%s" not found. Use list_collections to see available collections.',
					$this->definition->collectionName,
				));
			}

			$resolved = $this->resolveFilters($args);
			$params   = $this->buildQueryParams($resolved, $persona);
			$result   = $this->indexQueryService->query($this->definition->collectionName, $params);

			$nonExposed = $this->schemaResolver->nonExposedProperties($collection);
			$renderable = $this->schemaResolver->renderableProperties($collection);

			$items = [];
			foreach ($result->items as $object) {
				// Strip non-exposed fields first.
				foreach ($nonExposed as $field) {
					unset($object[$field]);
				}

				// Render content fields per the agent's chosen format.
				foreach ($renderable as $field) {
					if (isset($object[$field])) {
						$object[$field] = $this->contentRenderer->render($object[$field], $this->definition->format);
					}
				}

				// Decorate with URL.
				$object['url'] = $this->objectUrlBuilder->buildUrl($collection, $object);

				$items[] = $object;
			}

			return [
				'content' => [
					[
						'type' => 'text',
						'text' => (string)json_encode(
							['items' => $items, 'count' => count($items)],
							JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
						),
					],
				],
			];
		} catch (SavedQueryToolException $e) {
			return $this->errorResult($e->getMessage() . ' ' . $e->recoveryHint);
		} catch (\Throwable $e) {
			return $this->errorResult(sprintf(
				'Tool "%s" failed: %s. Try list_collections to verify the collection exists.',
				$this->definition->name,
				$e->getMessage(),
			));
		}
	}

	private function personaCanAccess(McpPersona $persona): bool
	{
		return match ($this->definition->access) {
			'public'        => true,
			'admin'         => $persona === McpPersona::ADMIN,
			'authenticated' => $persona !== McpPersona::PUBLIC_,
			default         => false,
		};
	}

	/**
	 * @param  array<string,mixed> $args
	 * @return array<string,array{value:mixed,operator:string}>
	 */
	private function resolveFilters(array $args): array
	{
		$resolved = [];
		foreach ($this->definition->filters as $field => $spec) {
			$resolved[$field] = [
				'value'    => $this->filterValueResolver->resolve(
					$spec['value'],
					$args,
					$this->definition->params,
					$this->definition->name,
				),
				'operator' => (string)($spec['operator'] ?? 'eq'),
			];
		}

		return $resolved;
	}

	/**
	 * @param  array<string,array{value:mixed,operator:string}> $resolved
	 * @return array<string,string>
	 */
	private function buildQueryParams(array $resolved, McpPersona $persona): array
	{
		// Build REST-style include string from the resolved filter map.
		$includeParts = [];
		foreach ($resolved as $field => $spec) {
			if ($spec['value'] === null) {
				continue; // optional param not supplied — skip this filter
			}

			$includeParts[] = $this->encodeFilterPart($field, $spec['operator'], $spec['value']);
		}

		$include = $this->definition->include;
		if ($includeParts !== []) {
			$include = trim($include . ',' . implode(',', $includeParts), ',');
		}

		// Persona safety filter applied last: public persona excludes drafts.
		$exclude = $this->definition->exclude;
		if ($persona === McpPersona::PUBLIC_) {
			$exclude = trim($exclude . ',draft:true', ',');
		}

		$params = [
			'limit'  => (string)$this->definition->limit,
			'offset' => (string)$this->definition->offset,
		];

		if ($this->definition->sort !== '') {
			$params['sort'] = $this->definition->sort;
		}

		if ($include !== '') {
			$params['include'] = $include;
		}

		if ($exclude !== '') {
			$params['exclude'] = $exclude;
		}

		return $params;
	}

	private function encodeFilterPart(string $field, string $op, mixed $value): string
	{
		$encoded = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;

		if ($op === 'eq') {
			return "{$field}:{$encoded}";
		}

		return "{$field}:{$op}:{$encoded}";
	}

	/**
	 * @return array<string,mixed>
	 */
	private function errorResult(string $message): array
	{
		return [
			'isError' => true,
			'content' => [
				['type' => 'text', 'text' => $message],
			],
		];
	}
}
