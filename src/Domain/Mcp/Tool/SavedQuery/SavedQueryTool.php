<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\SavedQuery;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\CollectionQueryResultFormatter;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;
use TotalCMS\Domain\Mcp\Tool\Service\FilterValueResolver;

/**
 * Runtime tool for a single schema-defined entry.
 *
 * Handler flow:
 *   1. Persona check (collection.mcp.access vs current persona).
 *   2. Group-read check (Task 10b) — PersonaContext::canReadCollection().
 *   3. Resolve {{params.X}} placeholders in filters via FilterValueResolver.
 *   4. Build REST-style include/exclude strings + persona-aware safety filters.
 *   5. Query via IndexQueryService.
 *   6. Strip non-exposed fields, render content via ContentRenderer,
 *      decorate items with URLs.
 *   7. Return the raw shared envelope built by
 *      CollectionQueryResultFormatter::envelope() — `{items, total, limit,
 *      offset, has_more}` (or throw ToolCallException).
 *
 * **Group-gated (Task 10b).** These tools are schema-defined per collection —
 * `$this->definition->collectionName` is fixed at registration, never a
 * caller-supplied argument — so there is no inputSchema property a
 * ToolRequirement's collectionArg could name (SchemaToolRegistrar registers
 * these with no `requires`; see its call site). Enforced inline instead, via
 * PersonaContext::canReadCollection() right after the collection is fetched
 * — same public-collection carve-out as the core content tools.
 *
 * **Result shape.** On success, returns the shared collection-query envelope
 * — `{items, total, limit, offset, has_more}` — built by
 * `CollectionQueryResultFormatter::envelope()` from the same `QueryResult`
 * `IndexQueryService::query()` hands back. This is the identical wire shape
 * `query_collection` returns (see QueryCollectionTool); a saved-query tool is
 * just `query_collection` with filters/sort/limit baked in at definition
 * time, so it reports the same pagination metadata rather than a bare
 * `count` that can't distinguish a complete result from a truncated one. No
 * hand-built `content` envelope — the SDK (`ToolReference::formatResult()` /
 * `extractStructuredContent()`) builds both the outer `content[0].text`
 * mirror and `structuredContent` from this raw return value, exactly like
 * every other core tool (see ListCollectionsTool, QueryCollectionTool).
 *
 * **Errors throw `Mcp\Exception\ToolCallException`** with a recovery hint
 * appended to the message. `CallToolHandler` catches it at the transport
 * boundary and builds a `CallToolResult` with `isError: true` — the same
 * convention every other core MCP tool uses (see GetObjectTool). A
 * hand-built `['isError' => true, ...]` return array does NOT set the outer
 * `CallToolResult.isError` — the SDK only inspects `isError` on a returned
 * `CallToolResult`/thrown `ToolCallException`, never on a raw array (see
 * `resources/docs/mcp/extensions.md`, "Returning data and reporting
 * errors").
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
		private CollectionQueryResultFormatter $resultFormatter,
	) {
	}

	/**
	 * @param  array<string,mixed> $args
	 *
	 * @return array{items: list<array<string,mixed>>, total: int, limit: int, offset: int, has_more: bool}
	 */
	public function handle(array $args): array
	{
		try {
			$persona = $this->personaContext->current();

			if (!$this->personaCanAccess($persona)) {
				throw new ToolCallException(
					'This tool requires admin access. The current connection is anonymous.',
				);
			}

			$collection = $this->collectionRepository->fetchCollection($this->definition->collectionName);
			if (!$collection instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
				throw new ToolCallException(sprintf(
					'Collection "%s" not found. Use list_collections to see available collections.',
					$this->definition->collectionName,
				));
			}

			if (!$this->personaContext->canReadCollection($this->definition->collectionName, $collection)) {
				throw new ToolCallException(sprintf(
					"Your account's groups do not grant read on '%s'.",
					$this->definition->collectionName,
				));
			}

			$resolved = $this->resolveFilters($args);
			$params   = $this->buildQueryParams($resolved);
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

			return $this->resultFormatter->envelope($result, $items);
		} catch (SavedQueryToolException $e) {
			throw new ToolCallException($e->getMessage() . ' ' . $e->recoveryHint);
		} catch (ToolCallException $e) {
			// Already the right shape (persona/collection/group-read checks
			// above) — rethrow unchanged rather than let the generic
			// \Throwable branch below re-wrap it with a less specific message.
			throw $e;
		} catch (\Throwable $e) {
			throw new ToolCallException(sprintf(
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
	 *
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
	 *
	 * @return array<string,string>
	 */
	private function buildQueryParams(array $resolved): array
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

		// Draft-authority safety filter applied last: only ADMIN or a caller
		// whose access groups grant `read` on this collection sees drafts —
		// see PersonaContext::canReadDrafts().
		$exclude = $this->definition->exclude;
		if (!$this->personaContext->canReadDrafts($this->definition->collectionName)) {
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
		if ($op === 'in' || $op === 'notin') {
			$list    = is_array($value) ? $value : [$value];
			$encoded = implode('|', array_map(fn (mixed $v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, $list));

			return "{$field}:{$op}:{$encoded}";
		}

		$encoded = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;

		if ($op === 'eq') {
			return "{$field}:{$encoded}";
		}

		return "{$field}:{$op}:{$encoded}";
	}
}
