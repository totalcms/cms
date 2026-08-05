<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaRemover;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * Admin tool family for schema CRUD.
 *
 * Registers five tools (`list_schemas`, `get_schema`, `create_schema`,
 * `update_schema`, `delete_schema`) as the operator's MCP-side surface for
 * managing schema definitions. Reads + writes split per tool to satisfy
 * Anthropic Directory review's "no mixed-mode tools" rule; destructive
 * `delete_schema` carries the appropriate annotation so MCP hosts can surface
 * it with extra confirmation.
 *
 * `list_schemas` and `get_schema` require admin persona — even reads are
 * operator work (inspecting schema definitions, not content) and shouldn't
 * surface to anonymous AI callers. `create_schema`, `update_schema`, and
 * `delete_schema` keep `access: 'admin'` but ALSO carry a `ToolRequirement`
 * (Phase 4): the requirement doesn't loosen the base persona gate (an
 * ADMIN-only tool stays hidden from AUTHENTICATED unless requires is
 * satisfied — access: 'authenticated' would short-circuit tools/list
 * visibility BEFORE requires is ever consulted, see
 * McpToolDefinition::isVisibleTo()), it EXTENDS it: an AUTHENTICATED caller
 * whose access-group grants schema create/update/delete on the target schema
 * id also sees and can call the matching tool — mirrors the REST
 * `/api/schemas` group gate. Domain-layer exceptions are converted to
 * `ToolCallException` with recovery hints so the SDK returns proper
 * `isError: true` MCP tool errors instead of letting raw exceptions escape.
 */
readonly class SchemaTools
{
	public function __construct(
		private SchemaLister $lister,
		private SchemaFetcher $fetcher,
		private SchemaSaver $saver,
		private SchemaRemover $remover,
		private ObjectSaver $objectSaver,
		private CollectionFetcher $collectionFetcher,
		private CollectionSaver $collectionSaver,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'list_schemas',
			description: 'List every schema available on the site. Returns id + description per schema. Use get_schema to fetch a specific schema by id.',
			access: 'admin',
			handler: $this->listHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			annotations: new ToolAnnotations(
				title: 'List Schemas',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
		));

		$registry->register(new McpToolDefinition(
			name: 'get_schema',
			description: 'Fetch the full definition of a single schema by id. Returns the schema as a JSON object (id, description, properties, required, index, etc.) — the same shape an operator would write into a schema file.',
			access: 'admin',
			handler: $this->getHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['id'],
				'additionalProperties' => false,
				'properties'           => [
					'id' => [
						'type'        => 'string',
						'description' => 'Schema id (slug-form). Use list_schemas to discover available schemas.',
						'examples'    => ['blog', 'products', 'auth'],
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Get Schema',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
		));

		$registry->register(new McpToolDefinition(
			name: 'create_schema',
			description: 'Create a new schema. Requires id + properties. Errors if the id collides with an existing or reserved schema. Use update_schema to modify an existing schema. Pass seedObjects to populate the new collection with initial content — the call emits SSE progress notifications while seeding so callers can track multi-step progress.',
			access: 'admin',
			requires: new ToolRequirement(domain: 'schemas', operation: 'create', collectionArg: 'id'),
			handler: $this->createHandler(...),
			inputSchema: $this->createInputSchema(),
			annotations: new ToolAnnotations(
				title: 'Create Schema',
				readOnlyHint: false,
				destructiveHint: false,
				// Repeating a create on the same id is an error, not a no-op.
				idempotentHint: false,
				openWorldHint: false,
			),
		));

		$registry->register(new McpToolDefinition(
			name: 'update_schema',
			description: 'Replace an existing schema definition. Pass the full new shape (properties, required, index, etc.). The id in the schema must match the id parameter. Use create_schema for new schemas; use get_schema first if you want to edit an existing schema rather than replace it wholesale.',
			access: 'admin',
			requires: new ToolRequirement(domain: 'schemas', operation: 'update', collectionArg: 'id'),
			handler: $this->updateHandler(...),
			inputSchema: $this->mutationInputSchema(),
			annotations: new ToolAnnotations(
				title: 'Update Schema',
				readOnlyHint: false,
				destructiveHint: false,
				// Same input = same final state.
				idempotentHint: true,
				openWorldHint: false,
			),
		));

		$registry->register(new McpToolDefinition(
			name: 'delete_schema',
			description: 'Delete a schema permanently. Rejects reserved schemas, schemas inherited by others, and schemas still used by a collection. There is no undo — the operator must restore from backup if needed.',
			access: 'admin',
			requires: new ToolRequirement(domain: 'schemas', operation: 'delete', collectionArg: 'id'),
			handler: $this->deleteHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['id'],
				'additionalProperties' => false,
				'properties'           => [
					'id' => [
						'type'        => 'string',
						'description' => 'Schema id to delete (slug-form). Use list_schemas to discover candidates.',
						'examples'    => ['old-portfolio', 'unused-schema'],
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Delete Schema',
				readOnlyHint: false,
				destructiveHint: true,
				idempotentHint: false,
				openWorldHint: false,
			),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function listHandler(): array
	{
		$schemas = [];
		foreach ($this->lister->listAllSchemas() as $schema) {
			$schemas[] = [
				'id'          => $schema->id,
				'description' => $schema->description,
				'category'    => $schema->category,
			];
		}

		return [
			'schemas' => $schemas,
			'total'   => count($schemas),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getHandler(string $id): array
	{
		try {
			$schema = $this->fetcher->fetchSchema($id);
		} catch (\UnexpectedValueException) {
			throw new ToolCallException(sprintf(
				'Schema "%s" not found. Use list_schemas to see available schemas.',
				$id,
			));
		}

		return $schema->toArray();
	}

	/**
	 * Create a new schema, optionally seeding initial objects into the collection.
	 *
	 * When $seedObjects is non-empty the handler emits two SSE progress
	 * notifications via the SDK's ClientGateway so callers can track multi-step
	 * progress. The gateway's progress() method is a no-op when the client did
	 * not include a progressToken in the request meta, so non-streaming callers
	 * are unaffected.
	 *
	 * @param array<string,array<string,mixed>>   $properties
	 * @param array<string,mixed>                 $extra       Additional schema fields (required, index, inheritFrom, description, category, formgrid)
	 * @param array<int,mixed>|null               $seedObjects Optional seed objects to create in the new collection
	 *
	 * @return array<string,mixed>
	 */
	public function createHandler(
		string $id,
		array $properties,
		array $extra = [],
		?array $seedObjects = null,
		?RequestContext $ctx = null,
	): array {
		$schemaData       = $extra;
		$schemaData['id'] = $id;
		// Properties array passed by the agent wins over anything in $extra.
		$schemaData['properties'] = $properties;

		try {
			$saved = $this->saver->saveSchema($schemaData);
		} catch (\InvalidArgumentException|\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf(
				'Could not create schema "%s": %s',
				$id,
				$e->getMessage(),
			), $e->getCode(), $e);
		}

		$result = $saved->toArray();

		// Seed initial objects when requested — emits progress notifications so
		// streaming clients can show step-by-step feedback. Non-streaming callers
		// (no progressToken) pass through the progress() calls silently.
		$seededCount = 0;
		if ($seedObjects !== null && $seedObjects !== []) {
			// The schema-domain requirement checked at call time (guardHandler)
			// only proves the caller can create SCHEMAS. Seeding also creates a
			// COLLECTION (collections-meta 'create') and writes OBJECTS into it
			// (objects 'create') — a caller with schemas.create but no
			// collections/collectionsMeta grants must not get those for free
			// just because they asked for seedObjects. The schema itself is
			// already persisted above (that write WAS authorized) — only the
			// seed step is refused here.
			$this->assertCanSeed($id);

			// Checkpoint 1: schema is on disk, about to seed objects.
			$ctx?->getClientGateway()->progress(50.0, 100.0, 'schema persisted');

			// Ensure the collection exists before writing objects. Try to
			// fetch first (reserved or already-created custom), then create
			// a new custom collection tied to this schema if not found.
			if (!$this->collectionFetcher->collectionExists($id)) {
				try {
					$this->collectionSaver->saveCollection([
						'id'     => $id,
						'schema' => $id,
					]);
				} catch (\DomainException) {
					// Already exists — either reserved schema's auto-collection or a
					// concurrent create. Other CollectionSaver failures (storage error,
					// permission, etc.) propagate so ObjectSaver doesn't run against an
					// invalid state.
				}
			}

			foreach ($seedObjects as $objectData) {
				if (!is_array($objectData)) {
					continue;
				}
				try {
					$this->objectSaver->saveObject($id, $objectData);
					$seededCount++;
				} catch (\Throwable $e) {
					throw new ToolCallException(sprintf(
						'Could not seed object into "%s": %s',
						$id,
						$e->getMessage(),
					), 0, $e);
				}
			}

			// Checkpoint 2: all seed objects written.
			$ctx?->getClientGateway()->progress(100.0, 100.0, 'seed objects created');

			$result['seeded'] = $seededCount;
		}

		return $result;
	}

	/**
	 * Guards the seedObjects path: creating a collection + writing objects
	 * into it needs collections-meta 'create' and objects 'create' on
	 * $collectionId, which schemas.create alone does not imply. Mirrors
	 * McpServerFactory::guardHandler()'s own persona/authority handling so
	 * the same caller shape (ADMIN bypasses, AUTHENTICATED is checked
	 * against their resolved UserAuthority) applies here — but this is a
	 * second, INDEPENDENT check inside the handler itself, not a substitute
	 * for the outer schemas-domain guard.
	 *
	 * No-op when PersonaContext was never resolved (e.g. a direct unit-test
	 * call to createHandler(), bypassing the MCP dispatch/guard entirely) —
	 * there is nothing to check a caller identity against in that shape, and
	 * unit tests calling the handler directly are implicitly trusted callers,
	 * same as every other handler method in this class.
	 */
	private function assertCanSeed(string $collectionId): void
	{
		if (!$this->personaContext->isResolved()) {
			return;
		}

		if ($this->personaContext->current() === McpPersona::ADMIN) {
			return;
		}

		$authority = $this->personaContext->getAuthority();
		if ($authority !== null && $authority->isAdmin) {
			return;
		}

		if (
			$authority === null
			|| !$authority->canCollectionMeta('create', $collectionId)
			|| !$authority->canCollection('create', $collectionId)
		) {
			throw new ToolCallException(sprintf(
				'create_schema: schema "%s" was created, but seedObjects was skipped — seeding also requires '
				. 'collectionsMeta \'create\' and objects \'create\' grants on "%s", which your account\'s groups '
				. 'do not provide. Create the collection and objects separately (create_collection + '
				. 'create_object), or ask an operator to widen your access group.',
				$collectionId,
				$collectionId,
			));
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $properties
	 * @param array<string,mixed>               $extra
	 *
	 * @return array<string,mixed>
	 */
	public function updateHandler(string $id, array $properties, array $extra = []): array
	{
		$schemaData               = $extra;
		$schemaData['id']         = $id;
		$schemaData['properties'] = $properties;

		try {
			$updated = $this->saver->updateSchema($id, $schemaData);
		} catch (\InvalidArgumentException|\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf(
				'Could not update schema "%s": %s',
				$id,
				$e->getMessage(),
			), $e->getCode(), $e);
		}

		return $updated->toArray();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function deleteHandler(string $id): array
	{
		try {
			$deleted = $this->remover->deleteSchema($id);
		} catch (\UnexpectedValueException|\DomainException $e) {
			throw new ToolCallException($e->getMessage(), $e->getCode(), $e);
		}

		return [
			'id'      => $id,
			'deleted' => $deleted,
		];
	}

	/**
	 * Input schema for create_schema — extends the shared mutation shape with an
	 * optional seedObjects array for initial content seeding.
	 *
	 * @return array<string,mixed>
	 */
	private function createInputSchema(): array
	{
		$base = $this->mutationInputSchema();

		$base['properties']['seedObjects'] = [
			'type'        => 'array',
			'description' => 'Optional list of objects to create in the new collection immediately after the schema is saved. When provided, the call emits SSE progress notifications (checkpoint 1: schema persisted; checkpoint 2: seed objects created). Each entry is a plain object whose keys match the schema properties.',
			'items'       => [
				'type'                 => 'object',
				'additionalProperties' => true,
			],
		];

		return $base;
	}

	/**
	 * Shared input schema for create + update — same shape, the dispatch is
	 * the difference (saveSchema vs updateSchema).
	 *
	 * @return array<string,mixed>
	 */
	private function mutationInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id', 'properties'],
			'additionalProperties' => false,
			'properties'           => [
				'id' => [
					'type'        => 'string',
					'description' => 'Schema id (slug-form).',
					'examples'    => ['portfolio', 'team-member'],
				],
				'properties' => [
					'type'        => 'object',
					'description' => 'Object of property definitions. Each key is the property name; each value is the property config (field, type, label, help, settings, etc.).',
					'examples'    => [
						[
							'title' => ['field' => 'text', 'label' => 'Title'],
							'body'  => ['field' => 'styledtext', 'label' => 'Body'],
						],
					],
				],
				'extra' => [
					// Accept either an object (with extra fields) or an empty array.
					// mcp/sdk ~0.5's SchemaValidator::convertDataForValidator() only
					// converts non-empty assoc arrays to stdClass — JSON `{}` arrives
					// as PHP `[]` and would fail strict `type: object` validation.
					// `oneOf` lets MCP clients (e.g. Inspector) submit empty {} OR [].
					// The handler tolerates both shapes server-side.
					'oneOf' => [
						['type' => 'object'],
						['type' => 'array', 'maxItems' => 0],
					],
					'description' => 'Optional additional schema-level fields: description, category, formgrid, required (array of names), index (array of names), inheritFrom (array of schema ids).',
					'default'     => new \stdClass(),
				],
			],
		];
	}
}
