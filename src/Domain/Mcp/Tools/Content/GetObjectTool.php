<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Content;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * `get_object(name, id)` — fetch a single object by its id from a collection.
 * Companion to `query_collection` (paginated lists) and `search_collection`
 * (full-text search); split deliberately so the AI doesn't have to construct
 * an `include="id:..."` filter for the common "show me that one" pattern.
 *
 * **Naming.** The tool returns one object, not the collection — hence
 * `get_object` rather than `get_collection`. `list_collections` is the tool
 * that returns collection-level metadata.
 *
 * **Draft visibility for public callers:** a draft is treated as if it didn't
 * exist (same error wording as a genuinely missing id), so guessing a slug
 * can't tell you whether the post exists in draft form.
 */
readonly class GetObjectTool
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
		private McpSchemaResolver $schemaResolver,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'get_object',
			description: $this->baseDescription(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			descriptionBuilder: $this->buildDescription(...),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(
		string $collection,
		string $id,
		string $format = 'markdown',
		string $locale = '',
	): array {
		// Forward-compat parameters; see QueryCollectionTool for the same note.
		unset($format, $locale);

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
			throw new ToolCallException(sprintf(
				'Collection "%s" not found. Use list_collections to see available collections.',
				$collection,
			));
		}

		$persona = $this->personaContext->current();
		if (!$this->schemaResolver->isAccessibleTo($collectionData, $persona->value)) {
			throw new ToolCallException(sprintf(
				'Collection "%s" is not available to the current caller. Use list_collections to see what you can query.',
				$collection,
			));
		}

		if (!$this->objectFetcher->existsObject($collection, $id)) {
			throw $this->notFound($collection, $id);
		}

		$object = $this->objectFetcher->fetchObject($collection, $id)->toArray();

		// Public callers can never see drafts. Returning the same error a missing
		// object produces keeps draft existence opaque — guessing a slug can't
		// distinguish "no post here" from "draft post here".
		if ($persona === McpPersona::PUBLIC_ && ($object['draft'] ?? false) === true) {
			throw $this->notFound($collection, $id);
		}

		$object['url'] = $this->urlBuilder->buildUrl($collectionData, $object);

		foreach ($this->schemaResolver->nonExposedProperties($collectionData) as $field) {
			unset($object[$field]);
		}

		return $object;
	}

	public function buildDescription(McpPersona $persona): string
	{
		$catalog = $this->schemaResolver->renderCatalog($persona, McpSchemaResolver::DEFAULT_CATALOG_CAP);

		return $catalog === ''
			? $this->baseDescription()
			: $this->baseDescription() . "\n\n" . $catalog;
	}

	private function baseDescription(): string
	{
		return implode(' ', [
			'Fetch a single object by its id from a collection.',
			'Returns the full object as a flat map of fields, decorated with a `url` field where the collection has a public URL pattern configured.',
			'This tool does not search or filter — use query_collection for paginated lists or search_collection for free-text search.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['collection', 'id'],
			'additionalProperties' => false,
			'properties'           => [
				'collection' => [
					'type'        => 'string',
					'description' => 'Collection id. Use list_collections to discover available collections.',
					'examples'    => ['blog', 'products'],
				],
				'id' => [
					'type'        => 'string',
					'description' => 'Object id (slug). Get candidate ids from query_collection or search_collection output.',
					'examples'    => ['hello-world', 'fall-collection-2025'],
				],
				'format' => [
					'type'        => 'string',
					'enum'        => ['markdown', 'html', 'text'],
					'default'     => 'markdown',
					'description' => 'Rendered format for content fields. Accepted now for forward-compat; full conversion ships with the Tiptap→markdown converter in 3.5.x.',
				],
				'locale' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'BCP 47 locale tag. Accepted for forward-compat with the 3.6 i18n release — has no effect today.',
				],
			],
		];
	}

	private function notFound(string $collection, string $id): ToolCallException
	{
		return new ToolCallException(sprintf(
			'Object "%s" not found in collection "%s". Use query_collection or search_collection to discover available object ids.',
			$id,
			$collection,
		));
	}
}
