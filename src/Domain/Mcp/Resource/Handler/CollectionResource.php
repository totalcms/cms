<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Handler;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;

/**
 * Handler for `resources/read tcms://{collection}/`.
 *
 * Returns the collection's most-recent items as a JSON resource body. Caps
 * output at 50 items and emits a `truncated` flag + pointer to `query_collection`
 * when the collection holds more than that. Persona check + field-expose
 * stripping mirror QueryCollectionTool so the resource surface and tool
 * surface stay consistent.
 *
 * **Group-authority read gate (Task 10, corrected Task 10b).** A resource
 * read is a bulk-browse surface: an AUTHENTICATED caller must pass
 * PersonaContext::canReadCollection($collection) — their access groups grant
 * `read` on $collection, OR $collection's `mcp.access` is `'public'` — or the
 * whole read is denied, not just its drafts. ADMIN is unaffected
 * (canReadCollection() always true for ADMIN); PUBLIC_ is unaffected (the
 * check only runs for AUTHENTICATED; PUBLIC_ never reaches an
 * `'authenticated'`-exposed collection at all via the isAccessibleTo() check
 * above it).
 *
 * Task 10 originally gated this with PersonaContext::canReadDrafts(), which
 * has NO public-collection carve-out — an AUTHENTICATED caller without a
 * group grant was denied even on a `mcp.access: 'public'` collection that an
 * ANONYMOUS caller could read freely (a privilege inversion: authenticating
 * reduced reach). Task 10b's canReadCollection() is the fix, and is now the
 * single home for this exact rule — also used by query_collection/get_object/
 * search_collection's call-time guard and by McpResourceDefinition::
 * authorizedFor() below.
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
	 * @return array<string,mixed> Flat resource content: {text, mimeType} — the SDK wraps it
	 */
	public function read(string $collection): array
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
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

		// AUTHENTICATED callers must additionally hold `read` on this specific
		// collection per their resolved access-group authority — UNLESS the
		// collection is `mcp.access: 'public'` (canReadCollection()'s carve-out;
		// see class docblock) — same message as the mcp.access denial above
		// (existing idiom: opaque "not accessible", no separate error shape for
		// "access-group denied" vs "mcp.access denied"). ADMIN and PUBLIC_
		// never reach this branch.
		if ($persona === McpPersona::AUTHENTICATED && !$this->personaContext->canReadCollection($collection, $collectionData)) {
			throw new ToolCallException(\sprintf(
				'Resource tcms://%s/ is not accessible to this caller. Use list_collections to see available collections.',
				$collection,
			));
		}

		$index = $this->indexReader->fetchIndex($collection);
		$items = $index->objects->all();

		// Drafts are hidden from anyone whose authority doesn't grant read on
		// this collection — PersonaContext::canReadDrafts() is the single home
		// for this rule (ADMIN always; an OAuth caller when their access
		// groups grant read; false for public/anonymous). Deliberately NOT
		// canReadCollection(): drafts always require the real grant, never
		// mere public exposure — an AUTHENTICATED caller with no group grant
		// reaches this line (not denied above) when $collection is
		// mcp.access:'public' (Task 10b's carve-out), and this filter still
		// strips their drafts even though the gate above admitted them. So
		// this line strips drafts for PUBLIC_ AND for that ungranted-but-
		// public-collection AUTHENTICATED case; ADMIN and a genuinely
		// grant-holding AUTHENTICATED caller are the only ones who keep them.
		if (!$this->personaContext->canReadDrafts($collection)) {
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

		// Flat {text, mimeType} — NOT a {contents: [...]} envelope. The SDK
		// builds the ReadResourceResult itself; returning a pre-built one made
		// it the *text* of the SDK's own envelope, burying the payload two
		// levels deep. See ResourceResultFormatter::format().
		return [
			'text'     => $json,
			'mimeType' => 'application/json',
		];
	}
}
