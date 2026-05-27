<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Admin tool family for collection management. Currently ships `create_collection`.
 *
 * Listing collections is handled by the public `list_collections` tool — the
 * admin caller already sees every collection through it (the persona filter
 * passes everything for admin), so a separate admin-only list tool would just
 * be redundant noise on the agent's surface.
 *
 * Delete is intentionally omitted — deleting a collection destroys all its
 * objects (high blast radius). Adding it would require extra safety nets
 * (explicit confirmation, dry-run mode) that aren't worth the cost yet.
 * Operators delete via the admin UI for now.
 */
readonly class CollectionTools
{
	public function __construct(
		private CollectionSaver $saver,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'create_collection',
			description: 'Create a new collection. Required: id (slug) + schema (existing schema id). Optional extra: name, url, description, category, prettyUrl, sortBy, reverseSort, groups, publicOperations, mcp settings. Errors if the id already exists.',
			access: 'admin',
			handler: $this->createHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['id', 'schema'],
				'additionalProperties' => false,
				'properties'           => [
					'id' => [
						'type'        => 'string',
						'description' => 'Collection id (slug-form). Must be unique across the site.',
						'examples'    => ['reviews', 'team', 'press-releases'],
					],
					'schema' => [
						'type'        => 'string',
						'description' => 'Existing schema id this collection should use. Discover via list_schemas.',
						'examples'    => ['review', 'auth', 'blog'],
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
						'description' => 'Optional additional collection fields: name (display label), url (pattern with {id} placeholders), description, category, prettyUrl (bool), sortBy, reverseSort (bool), groups (array), publicOperations (array), mcp (object).',
						'default'     => new \stdClass(),
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Create Collection',
				readOnlyHint: false,
				destructiveHint: false,
				idempotentHint: false,
				openWorldHint: false,
			),
		));
	}

	/**
	 * @param array<string,mixed> $extra
	 *
	 * @return array<string,mixed>
	 */
	public function createHandler(string $id, string $schema, array $extra = []): array
	{
		$data           = $extra;
		$data['id']     = $id;
		$data['schema'] = $schema;

		try {
			$collection = $this->saver->saveCollection($data);
		} catch (\DomainException|\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf(
				'Could not create collection "%s": %s',
				$id,
				$e->getMessage(),
			), $e->getCode(), $e);
		}

		return $collection->toArray();
	}
}
