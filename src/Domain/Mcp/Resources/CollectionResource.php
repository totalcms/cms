<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resources;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;

/**
 * Handler for `resources/read tcms://{collection}/`.
 *
 * Returns the collection's most-recent items as a JSON resource body. Caps
 * output at 50 items and emits a `truncated` flag + pointer to `query_collection`
 * when the collection holds more than that. Persona check + field-expose
 * stripping mirror QueryCollectionTool so the resource surface and tool
 * surface stay consistent.
 *
 * Mounted into ResourceRegistry by CollectionResourceRegistrar (Task A6); this
 * class never registers itself.
 */
readonly class CollectionResource
{
	private const MAX_ITEMS = 50;

	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private IndexReader $indexReader,
		private McpSchemaResolver $schemaResolver,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
	) {
	}

	/**
	 * @return array<string,mixed> Resource-read response shape: {contents: [{uri, mimeType, text}]}
	 */
	public function read(string $collection): array
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
			throw new ToolCallException(\sprintf(
				'Collection "%s" not found. Use list_collections to see available collections.',
				$collection,
			));
		}

		$persona = $this->personaContext->current();
		if (!$this->schemaResolver->isAccessibleTo($collectionData, $persona->value)) {
			throw new ToolCallException(\sprintf(
				'Resource tcms://%s/ is not accessible to this caller. Use list_collections to see available collections.',
				$collection,
			));
		}

		$index = $this->indexReader->fetchIndex($collection);
		$items = $index->objects->all();

		// Public callers never see drafts (matches QueryCollectionTool's safety merge)
		if ($persona === McpPersona::PUBLIC_) {
			$items = array_values(array_filter(
				$items,
				static fn (array $i): bool => empty($i['draft']),
			));
		}

		// `total` is intentionally the post-filter count — it reflects what
		// the persona can see, not the raw index size. Public callers see
		// `total: 55` for a 60-item collection with 5 drafts.
		$total      = count($items);
		$truncated  = $total > self::MAX_ITEMS;
		$displayed  = array_slice($items, 0, self::MAX_ITEMS);
		$nonExposed = $this->schemaResolver->nonExposedProperties($collectionData);

		foreach ($displayed as $idx => $item) {
			foreach ($nonExposed as $field) {
				unset($item[$field]);
			}
			$item['url']       = $this->urlBuilder->buildUrl($collectionData, $item);
			$displayed[$idx]   = $item;
		}

		$payload = [
			'items'     => $displayed,
			'total'     => $total,
			'truncated' => $truncated,
		];
		if ($truncated) {
			$payload['hint'] = \sprintf(
				'Showing %d of %d items. Call query_collection({name: "%s", ...}) with filters or paging for the rest.',
				self::MAX_ITEMS,
				$total,
				$collection,
			);
		}

		$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode resource payload.');
		}

		return [
			'contents' => [
				[
					'uri'      => \sprintf('tcms://%s/', $collection),
					'mimeType' => 'application/json',
					'text'     => $json,
				],
			],
		];
	}
}
