<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
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
 * All five tools require admin persona — even the reads are operator work
 * (inspecting schema definitions, not content) and shouldn't surface to
 * anonymous AI callers. Domain-layer exceptions are converted to
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
			description: 'Create a new schema. Requires id + properties. Errors if the id collides with an existing or reserved schema. Use update_schema to modify an existing schema.',
			access: 'admin',
			handler: $this->createHandler(...),
			inputSchema: $this->mutationInputSchema(),
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
		} catch (\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf(
				'Schema "%s" not found. Use list_schemas to see available schemas.',
				$id,
			));
		}

		return $schema->toArray();
	}

	/**
	 * @param array<string,array<string,mixed>> $properties
	 * @param array<string,mixed>               $extra      Additional schema fields (required, index, inheritFrom, description, category, formgrid)
	 *
	 * @return array<string,mixed>
	 */
	public function createHandler(string $id, array $properties, array $extra = []): array
	{
		$schemaData       = $extra;
		$schemaData['id'] = $id;
		// Properties array passed by the agent wins over anything in $extra.
		$schemaData['properties'] = $properties;

		try {
			$saved = $this->saver->saveSchema($schemaData);
		} catch (\InvalidArgumentException | \UnexpectedValueException $e) {
			throw new ToolCallException(sprintf(
				'Could not create schema "%s": %s',
				$id,
				$e->getMessage(),
			));
		}

		return $saved->toArray();
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
		} catch (\InvalidArgumentException | \UnexpectedValueException $e) {
			throw new ToolCallException(sprintf(
				'Could not update schema "%s": %s',
				$id,
				$e->getMessage(),
			));
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
		} catch (\UnexpectedValueException | \DomainException $e) {
			throw new ToolCallException($e->getMessage());
		}

		return [
			'id'      => $id,
			'deleted' => $deleted,
		];
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
					'type'        => 'object',
					'description' => 'Optional additional schema-level fields: description, category, formgrid, required (array of names), index (array of names), inheritFrom (array of schema ids).',
					'default'     => new \stdClass(),
				],
			],
		];
	}
}
