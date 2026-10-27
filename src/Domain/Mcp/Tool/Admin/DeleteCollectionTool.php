<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionRemover;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * `delete_collection`: delete a collection's settings.
 *
 * Deleting a collection removes its settings file only, as it does in the
 * admin. Its objects and files stay on disk, so creating a collection with
 * the same id brings them back; the result carries the deleted settings for
 * that, and they are snapshotted to the backup history too. The site's login
 * collections and system collections are refused.
 *
 * Needs the collection-settings delete grant (over OAuth, the cms:admin
 * scope), and a caller other than an admin reaches only collections MCP
 * exposes to it.
 */
final readonly class DeleteCollectionTool
{
	/** Counters and timestamps the site keeps itself. */
	private const MANAGED_KEYS = ['count', 'totalObjects', 'lastUpdated', 'updated', 'format'];

	public function __construct(
		private CollectionFetcher $fetcher,
		private CollectionRemover $remover,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'delete_collection',
			description: 'Delete a collection\'s settings. Required: id. Confirm with the user before calling. Its objects and their files are NOT deleted: they stay on disk, hidden until a collection with the same id is created again, which brings them back. The result returns the deleted settings; pass them to create_collection (as extra, with the same id and schema) to recreate the collection. Refuses the site\'s login collections and system collections.',
			access: 'admin',
			handler: $this->deleteHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['id'],
				'additionalProperties' => false,
				'properties'           => [
					'id' => [
						'type'        => 'string',
						'description' => 'Id of the collection to delete.',
						'examples'    => ['old-news'],
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Delete Collection',
				readOnlyHint: false,
				destructiveHint: true,
				idempotentHint: false,
				openWorldHint: false,
			),
			requires: new ToolRequirement(domain: 'collections-meta', operation: 'delete', collectionArg: 'id'),
		));
	}

	/**
	 * @return array{deleted: bool, id: string, objectsKept: int, settings: array<string,mixed>}
	 */
	public function deleteHandler(string $id): array
	{
		$collection = $this->existing($id);

		$protected = [...SchemaData::SYSTEM_COLLECTIONS, ...$this->remover->loginCollections()];
		if (in_array($id, $protected, true)) {
			throw new ToolCallException(sprintf('delete_collection: "%s" is a login or system collection; it cannot be deleted over MCP.', $id));
		}

		// The remover snapshots the settings to the backup history.
		$settings = array_diff_key($collection->toArray(), array_flip(self::MANAGED_KEYS));

		return [
			'deleted'     => $this->remover->deleteCollection($id),
			'id'          => $id,
			'objectsKept' => max(0, $collection->totalObjects),
			'settings'    => $settings,
		];
	}

	/**
	 * The collection, when it exists and the caller may reach it.
	 */
	private function existing(string $id): CollectionData
	{
		$collection = $this->fetcher->fetchCollection($id);
		if (!$collection instanceof CollectionData) {
			throw new ToolCallException(sprintf('delete_collection: collection "%s" not found. Use list_collections to discover available collections.', $id));
		}

		try {
			$this->personaContext->exposedCollection($id, 'access');
		} catch (ToolCallException $e) {
			throw new ToolCallException('delete_collection: ' . lcfirst($e->getMessage()), $e->getCode(), $e);
		}

		return $collection;
	}
}
