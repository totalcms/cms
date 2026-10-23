<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/**
 * `delete_object`: deletes one object, as the admin's delete button does.
 *
 * Who may call it follows the other write tools: an admin; an OAuth member
 * whose groups grant `delete` on the collection (their own objects only on
 * an owned collection); an API key whose paths name the collection and whose
 * methods include DELETE. The storage layer checks the same again.
 *
 * The record is kept in the backup history (when backups are on), so an
 * operator can restore it. Uploaded files are not: the object's folder goes
 * with it, and the result says which fields held files.
 */
final readonly class DeleteObjectTool
{
	private const FILE_FIELDS = ['image', 'file', 'gallery', 'depot'];

	public function __construct(
		private ObjectRemover $remover,
		private ObjectFetcher $objectFetcher,
		private SchemaFetcher $schemaFetcher,
		private PersonaContext $personaContext,
		private Config $config,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'delete_object',
			description: 'Delete ONE object. Required: collection + id. Confirm with the user before calling. The record goes to the site\'s backup history (when backups are on) and an operator can restore it, but its uploaded files (image, file, gallery and depot fields) are deleted permanently. The result lists the fields that held files and whether a backup was kept.',
			access: 'admin',
			handler: $this->handler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['collection', 'id'],
				'additionalProperties' => false,
				'properties'           => [
					'collection' => [
						'type'        => 'string',
						'description' => 'Collection id containing the object.',
						'examples'    => ['blog', 'team'],
					],
					'id' => [
						'type'        => 'string',
						'description' => 'Id of the object to delete. Use query_collection or search_collection to discover.',
						'examples'    => ['my-first-post'],
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Delete Object',
				readOnlyHint: false,
				destructiveHint: true,
				idempotentHint: true,
				openWorldHint: false,
			),
			requires: new ToolRequirement(domain: 'objects', operation: 'delete', collectionArg: 'collection'),
		));
	}

	/**
	 * @return array{deleted: bool, collection: string, id: string, filesRemoved: list<string>, backup: bool}
	 */
	public function handler(string $collection, string $id): array
	{
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
			$this->personaContext->exposedCollection($collection, 'access');
		} catch (\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf('delete_object: collection "%s" not found. Use list_collections to discover available collections.', $collection), $e->getCode(), $e);
		} catch (ToolCallException $e) {
			throw new ToolCallException('delete_object: ' . lcfirst($e->getMessage()), $e->getCode(), $e);
		}

		if (!$this->objectFetcher->existsObject($collection, $id)
			|| !$this->personaContext->mayChangeObject($collection, $id, Operation::Delete)) {
			throw new ToolCallException(sprintf('delete_object: object "%s/%s" not found. Use query_collection to discover ids.', $collection, $id));
		}

		$stored       = $this->objectFetcher->fetchObject($collection, $id)->toArray();
		$filesRemoved = [];
		foreach ($schema->properties as $name => $property) {
			if (in_array($property['field'] ?? '', self::FILE_FIELDS, true) && $this->holdsFiles($stored[$name] ?? null)) {
				$filesRemoved[] = (string)$name;
			}
		}

		try {
			$deleted = $this->remover->deleteObject($collection, $id);
		} catch (AccessDeniedException $e) {
			throw new ToolCallException(sprintf('Could not delete object "%s/%s": %s', $collection, $id, $e->getMessage()), $e->getCode(), $e);
		}

		return [
			'deleted'      => $deleted,
			'collection'   => $collection,
			'id'           => $id,
			'filesRemoved' => $filesRemoved,
			'backup'       => $deleted && (bool)($this->config->backups['enable'] ?? true),
		];
	}

	/**
	 * Whether a stored image, file, gallery or depot value has any upload:
	 * a `name` (image, file), an item (gallery) or a file (depot).
	 */
	private function holdsFiles(mixed $value): bool
	{
		if (!is_array($value) || $value === []) {
			return false;
		}
		if (array_is_list($value)) {
			return true;
		}

		return (string)($value['name'] ?? '') !== '' || !empty($value['files']);
	}
}
