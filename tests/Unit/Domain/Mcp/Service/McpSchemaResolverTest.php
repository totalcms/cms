<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class McpSchemaResolverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionRepository;
	private McpSchemaResolver $resolver;

	protected function setUp(): void
	{
		$this->schemaFetcher        = $this->createMock(SchemaFetcher::class);
		$this->collectionRepository = $this->createMock(CollectionRepository::class);
		$this->resolver             = new McpSchemaResolver(
			$this->schemaFetcher,
			new McpDescriptionResolver(),
			$this->collectionRepository,
		);
	}

	private function collection(array $mcp = []): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';
		$collection->mcp    = $mcp;

		return $collection;
	}

	/**
	 * @param array<string,array<string,mixed>> $properties
	 * @param array<int,string>|null            $index Defaults to "every property is
	 *                                                 indexed" so existing tests keep
	 *                                                 measuring field-type behavior
	 *                                                 without redundant boilerplate.
	 *                                                 Pass an explicit list (including
	 *                                                 []) when index-gate behavior is
	 *                                                 the actual assertion.
	 */
	private function schemaWithProperties(array $properties, ?array $index = null): SchemaData
	{
		$schema             = new SchemaData();
		$schema->id         = 'blog';
		$schema->properties = $properties;
		$schema->index      = $index ?? array_keys($properties);

		return $schema;
	}

	// ─── Collection-level config resolution ───────────────────────────────────

	public function testForCollectionDefaultsAccessToAdmin(): void
	{
		$result = $this->resolver->forCollection($this->collection([]));

		$this->assertSame('admin', $result['access']);
	}

	public function testForCollectionHonorsStoredAccess(): void
	{
		$result = $this->resolver->forCollection($this->collection(['access' => 'public']));

		$this->assertSame('public', $result['access']);
	}

	public function testForCollectionRejectsInvalidAccessValueAsAdmin(): void
	{
		// Defensive: a corrupted/garbage access value falls back to the
		// safest interpretation (admin = deny anonymous).
		$result = $this->resolver->forCollection($this->collection(['access' => 'nonsense']));

		$this->assertSame('admin', $result['access']);
	}

	public function testForCollectionDefaultsResourceToFalse(): void
	{
		// Unset means off: a collection is only addressable as a `tcms://`
		// resource when someone turned it on. Resources are a second route to
		// data the tools already serve, so they are opt-in.
		$result = $this->resolver->forCollection($this->collection([]));

		$this->assertFalse($result['resource']);
	}

	public function testForCollectionHonorsExplicitResourceTrue(): void
	{
		// A collection that opted in stays in — the flipped default only changes
		// collections that never stored a value.
		$result = $this->resolver->forCollection($this->collection(['resource' => true]));

		$this->assertTrue($result['resource']);
	}

	public function testForCollectionHonorsExplicitResourceFalse(): void
	{
		$result = $this->resolver->forCollection($this->collection(['resource' => false]));

		$this->assertFalse($result['resource']);
	}

	public function testForCollectionDefaultsTitlePropertyToEmptyString(): void
	{
		$result = $this->resolver->forCollection($this->collection([]));
		$this->assertSame('', $result['titleProperty']);
	}

	public function testForCollectionReturnsConfiguredTitleProperty(): void
	{
		$result = $this->resolver->forCollection($this->collection(['titleProperty' => 'headline']));
		$this->assertSame('headline', $result['titleProperty']);
	}

	public function testForCollectionCoercesNonStringTitlePropertyToEmpty(): void
	{
		$result = $this->resolver->forCollection($this->collection(['titleProperty' => ['oops']]));
		$this->assertSame('', $result['titleProperty']);
	}

	// ─── Persona access policy ────────────────────────────────────────────────

	public function testAdminAccessibleByAdminPersona(): void
	{
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'admin']), 'admin'));
	}

	public function testAdminNotAccessibleByPublicPersona(): void
	{
		$this->assertFalse($this->resolver->isAccessibleTo($this->collection(['access' => 'admin']), 'public'));
	}

	public function testPublicAccessibleByPublicPersona(): void
	{
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'public']), 'public'));
	}

	public function testPublicAccessibleByAdminPersona(): void
	{
		// Admin sees everything regardless of collection access.
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'public']), 'admin'));
	}

	public function testAuthenticatedSeesPublicCollections(): void
	{
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'public']), 'authenticated'));
	}

	// ─── describeProperties() — full schema describe with per-property flags ─

	public function testDescribePropertiesInfersFilterableForTextProperty(): void
	{
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text', 'label' => 'Title'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$this->assertCount(1, $properties);
		$this->assertSame('title', $properties[0]['name']);
		$this->assertSame('text', $properties[0]['type']);
		$this->assertTrue($properties[0]['filterable']);
		$this->assertFalse($properties[0]['sortable']);
	}

	public function testDescribePropertiesInfersFilterableAndSortableForIdProperty(): void
	{
		// The `id` form-field type (used by every collection's slug-like identifier
		// — see resources/schemas/blog.json) is the natural key for filtering and
		// sorting. Treat it as filterable + sortable by default so the catalog
		// surfaces it without operators having to set explicit mcp flags.
		$schema = $this->schemaWithProperties([
			'id' => ['field' => 'id', 'label' => 'Blog Post ID'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$this->assertCount(1, $properties);
		$this->assertSame('id', $properties[0]['name']);
		$this->assertTrue($properties[0]['filterable']);
		$this->assertTrue($properties[0]['sortable']);
	}

	public function testDescribePropertiesInfersSortableForNumberAndDate(): void
	{
		$schema = $this->schemaWithProperties([
			'price' => ['field' => 'number', 'label' => 'Price'],
			'date'  => ['field' => 'date', 'label' => 'Date'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$this->assertTrue($properties[0]['filterable']);
		$this->assertTrue($properties[0]['sortable']);
		$this->assertTrue($properties[1]['filterable']);
		$this->assertTrue($properties[1]['sortable']);
	}

	// ─── Index gate — non-indexed properties are listed but flagged unqueryable ─

	public function testDescribePropertiesIncludesNonIndexedPropertiesWithFlagsFalse(): void
	{
		// New semantics (lean list_collections + describe_collection split):
		// non-indexed properties STILL appear in describe_collection output so
		// the agent learns they exist on objects — but with indexed: false (so
		// they don't appear in query/search results) and filterable/sortable
		// false (cannot be queried at all). The agent retrieves them via
		// get_object.
		$schema = $this->schemaWithProperties(
			[
				'title' => ['field' => 'text'],
				'body'  => ['field' => 'styledtext'],  // present in schema, NOT in index
			],
			index: ['title'],
		);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$byName = array_column($properties, null, 'name');
		$this->assertTrue($byName['title']['indexed']);
		$this->assertTrue($byName['title']['filterable']);
		$this->assertFalse($byName['body']['indexed']);
		$this->assertFalse($byName['body']['filterable']);
		$this->assertFalse($byName['body']['sortable']);
	}

	public function testDescribePropertiesIndexedFlagInvariantHolds(): void
	{
		// The invariant: indexed=false implies filterable=false and sortable=false.
		// (Reverse not true: indexed=true + operator demote = filterable=false
		// even though indexed.) This invariant is what makes `indexed` the
		// authoritative signal for "appears in query results?".
		$schema = $this->schemaWithProperties(
			[
				'a' => ['field' => 'text'],
				'b' => ['field' => 'number'],
				'c' => ['field' => 'date', 'mcp' => ['filterable' => true, 'sortable' => true]],
			],
			index: [],  // nothing indexed
		);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		foreach ($properties as $prop) {
			$this->assertFalse($prop['indexed']);
			$this->assertFalse($prop['filterable']);
			$this->assertFalse($prop['sortable']);
		}
	}

	public function testDescribePropertiesEmptyIndexFlagsEveryPropertyUnqueryable(): void
	{
		// Schema with no indexed properties: every property shows up but all
		// flags read false. Honest — agent learns the shape but doesn't try
		// to filter.
		$schema = $this->schemaWithProperties(
			[
				'title' => ['field' => 'text'],
				'body'  => ['field' => 'textarea'],
			],
			index: [],
		);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$this->assertCount(2, $properties);
		foreach ($properties as $prop) {
			$this->assertFalse($prop['indexed']);
			$this->assertFalse($prop['filterable']);
			$this->assertFalse($prop['sortable']);
		}
	}

	public function testDescribePropertiesIgnoresOperatorFilterableSortableOverrides(): void
	{
		// The per-property mcp.filterable / mcp.sortable overrides were dropped:
		// the index + field-type combination is the sole source of truth, and
		// operators steer filtering by editing the schema's `index` list (the
		// real lever) rather than per-property MCP knobs. Any persisted
		// mcp.filterable / mcp.sortable values become inert.
		//
		// This test pins the new behavior: an indexed filterable-typed property
		// remains filterable even when the operator explicitly set
		// mcp.filterable:false. Mirror for sortable.
		$schema = $this->schemaWithProperties(
			[
				'title' => ['field' => 'text', 'mcp' => ['filterable' => false]],
				'date'  => ['field' => 'date', 'mcp' => ['sortable' => false]],
			],
			index: ['title', 'date'],
		);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());
		$byName     = array_column($properties, null, 'name');

		// Override ignored — text is filterable by default for indexed properties.
		$this->assertTrue($byName['title']['filterable']);
		// Override ignored — date is sortable by default.
		$this->assertTrue($byName['date']['sortable']);
	}

	public function testDescribePropertiesStripsNonExposedProperties(): void
	{
		// expose:false → property shouldn't appear in AI metadata at all.
		// Distinct from non-indexed: those are listed with flags false; these
		// are hidden entirely.
		$schema = $this->schemaWithProperties([
			'title'          => ['field' => 'text'],
			'internal_notes' => ['field' => 'textarea', 'mcp' => ['expose' => false]],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$this->assertCount(1, $properties);
		$this->assertSame('title', $properties[0]['name']);
	}

	public function testDescribePropertiesIncludesDescriptionViaFallbackChain(): void
	{
		$schema = $this->schemaWithProperties([
			'a' => ['field' => 'text', 'mcp' => ['description' => 'mcp-desc']],
			'b' => ['field' => 'text', 'help' => 'help-desc'],
			'c' => ['field' => 'text', 'label' => 'C Label'],
			'd' => ['field' => 'text'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$properties = $this->resolver->describeProperties($this->collection());

		$this->assertSame('mcp-desc', $properties[0]['description']);
		$this->assertSame('help-desc', $properties[1]['description']);
		$this->assertSame('C Label', $properties[2]['description']);
		$this->assertNull($properties[3]['description']);
	}

	// ─── isPropertyExposed ────────────────────────────────────────────────────

	public function testIsPropertyExposedDefaultsTrue(): void
	{
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text'],
		]);

		$this->assertTrue($this->resolver->isPropertyExposed($schema, 'title'));
	}

	public function testIsPropertyExposedFalseWhenMcpExposeFalse(): void
	{
		$schema = $this->schemaWithProperties([
			'cost' => ['field' => 'number', 'mcp' => ['expose' => false]],
		]);

		$this->assertFalse($this->resolver->isPropertyExposed($schema, 'cost'));
	}

	public function testIsPropertyExposedTrueForUnknownProperty(): void
	{
		// Defensive: querying a property the schema doesn't define returns
		// true (default permissive — caller should validate property exists
		// before exposing decisions to logic).
		$schema = $this->schemaWithProperties([]);

		$this->assertTrue($this->resolver->isPropertyExposed($schema, 'missing'));
	}

	public function testIsPropertyExposedDefaultsFalseForPasswordField(): void
	{
		// Sensitive-field default — password fields default to non-exposed even
		// when no explicit mcp.expose key is set. Catches the common case
		// (hashed user password) without requiring per-schema opt-out.
		$schema = $this->schemaWithProperties([
			'password' => ['field' => 'password'],
		]);

		$this->assertFalse($this->resolver->isPropertyExposed($schema, 'password'));
	}

	public function testIsPropertyExposedDefaultsFalseForSecretField(): void
	{
		// API keys / tokens / OAuth secrets stored via SecretField default
		// to non-exposed for the same reason as password.
		$schema = $this->schemaWithProperties([
			'apiKey' => ['field' => 'secret'],
		]);

		$this->assertFalse($this->resolver->isPropertyExposed($schema, 'apiKey'));
	}

	public function testIsPropertyExposedExplicitTrueOverridesSensitiveDefault(): void
	{
		// Operators who genuinely want a sensitive field surfaced can opt
		// back in with mcp.expose: true. Explicit beats default in both
		// directions.
		$schema = $this->schemaWithProperties([
			'password' => ['field' => 'password', 'mcp' => ['expose' => true]],
		]);

		$this->assertTrue($this->resolver->isPropertyExposed($schema, 'password'));
	}

	// ─── nonExposedProperties() — list of properties to strip from MCP output ─

	// ─── renderableProperties() — properties whose values are HTML (styledtext) ─

	public function testRenderablePropertiesListsStyledtextAndLocalizedstyledtext(): void
	{
		// Content tools call this once per request and transform each listed
		// property's value through ContentRenderer. Both styledtext (plain
		// HTML string) and localizedstyledtext (locale-keyed object of HTML)
		// qualify — ContentRenderer is polymorphic on the input shape.
		$schema = $this->schemaWithProperties([
			'title'   => ['field' => 'text'],
			'summary' => ['field' => 'styledtext'],
			'date'    => ['field' => 'date'],
			'body'    => ['field' => 'localizedstyledtext'],
			'extra'   => ['field' => 'textarea'],
			'caption' => ['field' => 'localizedtext'],  // plain localized text — agent sees verbatim
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame(['summary', 'body'], $this->resolver->renderableProperties($this->collection()));
	}

	public function testRenderablePropertiesReturnsEmptyWhenNoStyledtextFields(): void
	{
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text'],
			'date'  => ['field' => 'date'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame([], $this->resolver->renderableProperties($this->collection()));
	}

	public function testNonExposedPropertiesReturnsEmptyListWhenAllExposed(): void
	{
		// When every property is exposed (or omits mcp.expose), tools have
		// nothing to strip and the helper returns an empty list.
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text'],
			'body'  => ['field' => 'styledtext'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame([], $this->resolver->nonExposedProperties($this->collection()));
	}

	public function testNonExposedPropertiesListsPropertiesWithMcpExposeFalse(): void
	{
		// Content tools (query_collection, get_object, search_collection)
		// strip these property names from each returned item.
		$schema = $this->schemaWithProperties([
			'title'    => ['field' => 'text'],
			'secret'   => ['field' => 'text', 'mcp' => ['expose' => false]],
			'admin_id' => ['field' => 'text', 'mcp' => ['expose' => false]],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame(['secret', 'admin_id'], $this->resolver->nonExposedProperties($this->collection()));
	}

	public function testNonExposedPropertiesIncludesSensitiveFieldsByDefault(): void
	{
		// Sensitive field types (password, secret) auto-populate the strip
		// list even when no mcp key is set on the property — content tools
		// drop them from query/search/get_object output the same way they
		// drop explicit mcp.expose:false fields.
		$schema = $this->schemaWithProperties([
			'name'     => ['field' => 'text'],
			'password' => ['field' => 'password'],
			'apiToken' => ['field' => 'secret'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame(['password', 'apiToken'], $this->resolver->nonExposedProperties($this->collection()));
	}

	public function testNonExposedPropertiesRespectsExplicitOptInOnSensitiveField(): void
	{
		// mcp.expose: true on a sensitive field flips the default — the field
		// no longer appears in the strip list. Symmetric with the explicit
		// opt-out path.
		$schema = $this->schemaWithProperties([
			'name'     => ['field' => 'text'],
			'password' => ['field' => 'password', 'mcp' => ['expose' => true]],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame([], $this->resolver->nonExposedProperties($this->collection()));
	}

	// ─── renderCatalog() — dynamic description field catalog ──────────────────

	public function testRenderCatalogReturnsEmptyStringWhenNoCollectionsVisible(): void
	{
		// Fresh-install case: zero collections registered. Content tools should
		// emit a description with no catalog block at all (the static base
		// description carries the load).
		$this->collectionRepository->method('listAllCollections')->willReturn([]);

		$this->assertSame('', $this->resolver->renderCatalog(McpPersona::ADMIN));
	}

	public function testRenderCatalogFiltersByPersonaAccess(): void
	{
		// Public persona must NOT see admin-only collections in the catalog —
		// otherwise the AI agent gets pointed at tools it'll be refused from.
		$adminOnly     = $this->collection(['access' => 'admin']);
		$adminOnly->id = 'auth';

		$publicBlog     = $this->collection(['access' => 'public']);
		$publicBlog->id = 'blog';

		$this->collectionRepository->method('listAllCollections')->willReturn([$adminOnly, $publicBlog]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$publicCatalog = $this->resolver->renderCatalog(McpPersona::PUBLIC_);
		$this->assertStringContainsString('blog', $publicCatalog);
		$this->assertStringNotContainsString('auth', $publicCatalog);

		// Admin persona sees both, regardless of per-collection access.
		$adminCatalog = $this->resolver->renderCatalog(McpPersona::ADMIN);
		$this->assertStringContainsString('blog', $adminCatalog);
		$this->assertStringContainsString('auth', $adminCatalog);
	}

	public function testRenderCatalogAppliesOptionalIsReadableFilterOnTopOfPersonaAccess(): void
	{
		// Task 10b fix round 1 (finding #1): an AUTHENTICATED caller's tool
		// description must not advertise a collection their access groups
		// don't grant read on, even when it passes the $access exposure
		// check. $isReadable is the caller-supplied hook for that — proven
		// here independent of any real PersonaContext/group wiring.
		$granted     = $this->collection(['access' => 'authenticated']);
		$granted->id = 'blog';

		$denied     = $this->collection(['access' => 'authenticated']);
		$denied->id = 'news';

		$this->collectionRepository->method('listAllCollections')->willReturn([$granted, $denied]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$catalog = $this->resolver->renderCatalog(
			McpPersona::AUTHENTICATED,
			McpSchemaResolver::DEFAULT_CATALOG_CAP,
			static fn (CollectionData $c): bool => $c->id === 'blog',
		);

		$this->assertStringContainsString('blog', $catalog);
		$this->assertStringNotContainsString('news', $catalog);
	}

	public function testRenderCatalogWithNullIsReadableMatchesPreExistingBehavior(): void
	{
		// Backward-compatibility guard: omitting $isReadable (every call
		// site before this task, and QueryViewTool-style callers that never
		// pass it) must behave byte-for-byte as before — access-only
		// filtering, no group awareness.
		$blog     = $this->collection(['access' => 'authenticated']);
		$blog->id = 'blog';

		$this->collectionRepository->method('listAllCollections')->willReturn([$blog]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::AUTHENTICATED);

		$this->assertStringContainsString('blog', $catalog);
	}

	public function testRenderCatalogLineFormatIncludesFieldsWithTypes(): void
	{
		// Each collection line follows: `- <id> — <field> (<type>[, sortable])`
		// AI agent reads this to know what include/exclude values to compose.
		$collection     = $this->collection(['access' => 'public']);
		$collection->id = 'products';

		$this->collectionRepository->method('listAllCollections')->willReturn([$collection]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			// `notes` is text (filterable type) but excluded from the index —
			// so it's listed as a property but not as a queryable. Catalog
			// renders only actionable properties; non-actionable are silently
			// elided from the line.
			$this->schemaWithProperties(
				[
					'featured' => ['field' => 'toggle'],
					'price'    => ['field' => 'number'],
					'notes'    => ['field' => 'text'],
				],
				index: ['featured', 'price'],
			),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		// Property metadata: toggle = filterable boolean, number = filterable + sortable.
		// Non-indexed properties are omitted from the line — they aren't
		// actionable for query composition (agent fetches them via get_object).
		$this->assertStringContainsString('- products', $catalog);
		$this->assertStringContainsString('featured (toggle)', $catalog);
		$this->assertStringContainsString('price (number, sortable)', $catalog);
		$this->assertStringNotContainsString('notes', $catalog);
	}

	public function testRenderCatalogCapsAtThirtyCollectionsByDefault(): void
	{
		// Plan: cap at ~30 to keep tool descriptions inside MCP host context
		// budgets. Overflow rolls into the closing pointer at list_collections.
		$collections = [];
		for ($i = 1; $i <= 35; $i++) {
			$c             = $this->collection(['access' => 'public']);
			$c->id         = sprintf('col%02d', $i);
			$collections[] = $c;
		}
		$this->collectionRepository->method('listAllCollections')->willReturn($collections);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		// First 30 listed; "Plus 5 more" overflow line follows.
		$this->assertStringContainsString('- col01', $catalog);
		$this->assertStringContainsString('- col30', $catalog);
		$this->assertStringNotContainsString('- col31', $catalog);
		$this->assertStringContainsString('Plus 5 more', $catalog);
		$this->assertStringContainsString('list_collections', $catalog);
	}

	public function testRenderCatalogOrdersCollectionsAlphabetically(): void
	{
		// Stable order so tools/list responses are diff-friendly across runs
		// (same input → same description string).
		$banana          = $this->collection(['access' => 'public']);
		$banana->id      = 'banana';
		$apple           = $this->collection(['access' => 'public']);
		$apple->id       = 'apple';
		$cherry          = $this->collection(['access' => 'public']);
		$cherry->id      = 'cherry';

		$this->collectionRepository->method('listAllCollections')->willReturn([$banana, $cherry, $apple]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		$applePos  = strpos($catalog, '- apple');
		$bananaPos = strpos($catalog, '- banana');
		$cherryPos = strpos($catalog, '- cherry');

		$this->assertNotFalse($applePos);
		$this->assertNotFalse($bananaPos);
		$this->assertNotFalse($cherryPos);
		$this->assertLessThan($bananaPos, $applePos);
		$this->assertLessThan($cherryPos, $bananaPos);
	}

	public function testRenderCatalogOmitsFieldDetailWhenNoFilterableFields(): void
	{
		// A collection whose schema has no filterable/sortable fields still
		// gets a catalog line — empty fields list is honest, not a bug.
		$collection     = $this->collection(['access' => 'public']);
		$collection->id = 'plain';

		$this->collectionRepository->method('listAllCollections')->willReturn([$collection]);
		// Property exists but isn't indexed → no actionable filter. Catalog
		// surfaces this honestly with the "(no filterable properties)" marker
		// so the agent doesn't try to compose an include/exclude on it.
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(
				['note' => ['field' => 'text']],
				index: [],
			),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		$this->assertStringContainsString('- plain', $catalog);
		$this->assertStringContainsString('(no filterable properties)', $catalog);
	}
}
