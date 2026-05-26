# Search Providers — Phase 5 Sub-Project Spec

**Status:** Design approved.
**Phase:** Phase 5 — first of three independent sub-projects. (Other Phase 5 work — MCP prompts, perf optimization — has its own future specs.)
**Effort:** ~3-4 days for T3 core hook + abstraction, plus ~2-3 days for the bundled Algolia reference extension (lands together).

## Goal

Make T3's content search pluggable. Customers can install an extension that swaps T3's built-in text search for a hosted search service (Algolia, OpenAI embeddings + vector DB, Meilisearch, Typesense, etc.) — bringing semantic / AI-aware search to AI agents AND any future site-wide search UI without changing how T3 itself stores content.

T3 core ships the abstraction + a built-in text-search provider (wrapping the existing `IndexSearcher`). MCP search tools route through the new abstraction in Phase 5; future REST API search endpoints and site-wide search extensions consume the same registry with no rework.

## Non-goals (explicitly out of scope)

- **Site-wide search UI / Stacks plugin** — separate paid extension product (not part of Phase 5)
- **Sitemap-crawling / page-aware search** — separate paid extension product (the user's planned `totalcms/site-search`)
- **Multiple bundled reference providers** — Algolia is the first reference, bundled at `resources/extensions/totalcms/algolia-search/`. OpenAI / Meilisearch / Typesense providers are post-Phase-5 work and ship as separate Composer packages (NOT bundled) to keep T3 core release scope manageable
- **In-PHP vector storage / cosine similarity** — semantic search without an external service is intentionally NOT in scope; doing it well requires a real vector DB or external service
- **Per-collection multi-provider routing** — v1 is single active provider site-wide; multi-provider can come later if requested
- **Search analytics dashboard** — defers to the broader Activity Dashboard (separate spec)
- **Replacing IndexBuilder / IndexData** — existing text-search storage stays; new abstraction wraps it as one provider among many

## Scope decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| Hook scope | T3-wide architecture, MCP-only implementation in Phase 5 | Future site-wide search extension consumes the same hook; no rework / no extension API churn |
| Provider responsibility | Query + Index lifecycle; T3 pushes content changes | Customer just installs extension and indexes populate automatically. Higher embedding-API cost but lowest ops burden |
| Multi-provider model | Single active provider site-wide | Simple mental model; matches "pick one embedding service" customer expectation. Per-schema opt-out to stay on text search |
| Query-failure semantics | Silent fallback to text search | Best UX; preserves search availability through provider outages |
| Index-failure semantics | Save succeeds; index update enqueued for JobQueue retry | Content authors never blocked by external service availability |
| First reference implementation | Algolia, bundled in T3 core at `resources/extensions/totalcms/algolia-search/` | Mature, complete product; validates full provider lifecycle in one API; best customer onboarding. Bundled (not external repo) so it ships with T3 releases, follows the same pattern as `ab-split` + `geo-redirect`, and signals "this is the canonical way to build a search provider" |
| Edition gating | Framework is free; extensions declare their own edition gates | Built-in text-search provider works on all editions. Paid extensions (Algolia, OpenAI) gate themselves to Pro via their own `EditionFeature` checks |
| Monetization protection | Phase 5 ships hook + abstraction + text-search default only. No reference UI components | Customer's paid extensions (site-wide search, Algolia) are the value-add layer above the free infrastructure |

## Architecture

### Core abstractions

**`SearchProvider`** interface — the extension API:

```php
namespace TotalCMS\Domain\Search\Service;

interface SearchProvider
{
    /**
     * Stable identifier for this provider. Used in settings + per-schema opt-outs.
     * Must be lowercase alphanumeric + hyphens (e.g., 'algolia', 'openai',
     * 'meilisearch', 'text').
     */
    public function id(): string;

    /**
     * Human-readable name for admin UI.
     */
    public function label(): string;

    /**
     * Execute a query against the provider's index. Returns ranked results.
     *
     * Implementations MUST honour $query->limit + $query->offset. Implementations
     * SHOULD honour $query->collection (null = cross-collection). The persona
     * + locale fields are forward-compat for content gating + i18n; v1 providers
     * may ignore them (text search uses them, semantic providers can choose).
     *
     * @return list<SearchResult>
     */
    public function search(SearchQuery $query): array;

    /**
     * Index (or re-index) one object. Called by ContentChangeListener on
     * object.created / object.updated events.
     *
     * Implementations should be idempotent: indexing the same id twice with
     * different data updates the indexed entry.
     *
     * @param array<string,mixed> $data Object data as stored in T3
     */
    public function index(string $collection, string $id, array $data): void;

    /**
     * Remove one object from the index. Called on object.deleted.
     */
    public function delete(string $collection, string $id): void;

    /**
     * Health check used by SearchService to decide whether to query the
     * provider or fall back to text search. Implementations SHOULD return
     * quickly (cached health check) — this is called on every search request.
     */
    public function isAvailable(): bool;
}
```

**`SearchQuery`** value object — what a caller asks for:

```php
namespace TotalCMS\Domain\Search\Data;

final readonly class SearchQuery
{
    public function __construct(
        public string $text,            // the query string
        public ?string $collection = null, // null = cross-collection
        public int $limit = 10,
        public int $offset = 0,
        public string $persona = 'public', // 'public' | 'authenticated' | 'admin'
        public string $locale = '',     // BCP 47, e.g. 'en_US' (forward-compat)
    ) {}
}
```

**`SearchResult`** value object — what a provider returns per hit:

```php
namespace TotalCMS\Domain\Search\Data;

final readonly class SearchResult
{
    public function __construct(
        public string $collection,
        public string $id,
        public float $score,          // normalised 0-1 (1 = best match)
        public ?string $snippet = null, // optional highlighted match
    ) {}
}
```

### Registry + service

**`SearchProviderRegistry`** — collects registered providers. Pre-populated with the built-in text provider; extensions add more during the `extension.register` lifecycle phase.

```php
namespace TotalCMS\Domain\Search\Service;

final class SearchProviderRegistry
{
    public function register(SearchProvider $provider): void;

    public function get(string $id): ?SearchProvider;

    /** @return list<SearchProvider> */
    public function all(): array;

    /**
     * The currently-active provider, per Config::$search['activeProvider'].
     * Returns null if the configured id is missing; SearchService falls back
     * to the text provider.
     */
    public function active(): ?SearchProvider;
}
```

**`SearchService`** — the public-facing API everything else (MCP tools, future REST endpoints, future site-wide extension) calls:

```php
namespace TotalCMS\Domain\Search\Service;

final readonly class SearchService
{
    public function __construct(
        private SearchProviderRegistry $registry,
        private TextSearchProvider $fallback,
        private LoggerInterface $logger,
    ) {}

    /** @return list<SearchResult> */
    public function search(SearchQuery $query): array
    {
        $provider = $this->resolveProvider($query);

        if ($provider === null || !$provider->isAvailable()) {
            return $this->fallback->search($query);
        }

        try {
            return $provider->search($query);
        } catch (\Throwable $e) {
            $this->logger->warning('Search provider failed; falling back to text search', [
                'provider' => $provider->id(),
                'error'    => $e->getMessage(),
                'query'    => $query->text,
            ]);
            return $this->fallback->search($query);
        }
    }

    /**
     * Resolves which provider to use for THIS query, honouring per-schema
     * opt-outs. If the collection's mcp.search.provider is 'text', we force
     * the fallback even when an active provider exists.
     */
    private function resolveProvider(SearchQuery $query): ?SearchProvider { ... }
}
```

### Built-in text provider

**`TextSearchProvider`** — wraps the existing `IndexSearcher` + `IndexFilter` so the new architecture is backward-compatible with no behaviour change for sites without external providers:

```php
namespace TotalCMS\Domain\Search\Service;

final readonly class TextSearchProvider implements SearchProvider
{
    public function __construct(
        private IndexSearcher $searcher,
        private IndexFilter $filter,
    ) {}

    public function id(): string { return 'text'; }
    public function label(): string { return 'Text Search (built-in)'; }

    public function search(SearchQuery $query): array
    {
        // Delegate to existing IndexSearcher; map results to SearchResult shape.
        ...
    }

    public function index(string $collection, string $id, array $data): void
    {
        // No-op. The existing IndexBuilder pipeline already updates IndexData
        // on save; this provider just reads what's already there.
    }

    public function delete(string $collection, string $id): void
    {
        // No-op. IndexBuilder handles removal on object.deleted.
    }

    public function isAvailable(): bool { return true; }
}
```

The text provider's index/delete methods are intentional no-ops — T3's existing `IndexBuilder` already maintains the `IndexData` files via events. The provider just reads them.

### Content change listener

**`ContentChangeListener`** — subscribes to the existing EventDispatcher events:

```php
namespace TotalCMS\Domain\Search\Listener;

readonly class ContentChangeListener
{
    public function __construct(
        private SearchProviderRegistry $registry,
        private JobQueue $jobs,
        private LoggerInterface $logger,
    ) {}

    public function onObjectSaved(ObjectEventPayload $event): void
    {
        $provider = $this->registry->active();
        if ($provider === null || $provider->id() === 'text') {
            return; // text provider handled by IndexBuilder
        }
        try {
            $provider->index($event->collection, $event->objectId, $event->data);
        } catch (\Throwable $e) {
            $this->logger->warning('Provider index failed; enqueueing for retry', [...]);
            $this->jobs->enqueue(new ReindexJob($event->collection, $event->objectId));
        }
    }

    public function onObjectDeleted(ObjectEventPayload $event): void
    {
        $provider = $this->registry->active();
        if ($provider === null || $provider->id() === 'text') {
            return;
        }
        try {
            $provider->delete($event->collection, $event->objectId);
        } catch (\Throwable $e) {
            $this->logger->warning('Provider delete failed; enqueueing for retry', [...]);
            $this->jobs->enqueue(new ReindexJob($event->collection, $event->objectId, isDelete: true));
        }
    }
}
```

Listener registers with EventDispatcher in `extension.boot` (or container build time for core).

### Bulk reindex job + CLI

**`ReindexJob`** — JobQueue job that retries failed index updates OR runs a bulk reindex from scratch. Picks up after provider outages without operator intervention.

**`SearchReindexCommand`** (`tcms search:reindex [<collection>] [--all]`) — iterates collection objects + calls `provider->index()` for each. Used for:
- Initial setup after installing a provider (bulk-index existing content)
- Recovery after extended provider outage
- Force reindex after a schema change

### Extension hook

```php
namespace TotalCMS\Domain\Extension;

class ExtensionContext
{
    /**
     * Register a search provider. The extension is responsible for the
     * provider's full lifecycle (index, query, delete) and any external
     * dependencies (API clients, vector stores).
     *
     * Collision policy: strict deny. If a registered provider id clashes with
     * the built-in 'text' provider OR another extension's provider, the second
     * registration is logged and skipped.
     */
    public function registerSearchProvider(SearchProvider $provider): void;
}
```

Same shape as `registerMcpTool` / `registerMcpResource`. Registered providers get drained into `SearchProviderRegistry` during the extension `boot` lifecycle phase.

### Per-schema opt-out

Schemas gain an optional `search` block:

```json
{
  "id": "products",
  "fields": [...],
  "mcp": { "access": "public", "description": "..." },
  "search": {
    "provider": "default"
  }
}
```

Values:
- `"default"` (or omitted) — use the active site-wide provider
- `"text"` — force text search; ignore any active semantic provider
- `"<provider_id>"` — use a specific provider IF registered (otherwise falls back to text)

Use case: a SKU-heavy products collection might prefer text search (exact-match for product codes) even when the site uses a semantic provider for blog content.

### Settings

`config/defaults.php` gains:

```php
$settings['search'] = [
    'enabled'        => true,
    'activeProvider' => 'text',  // 'text' | 'algolia' | etc.
    'indexOnSave'    => true,     // disable during bulk imports
];
```

Settings UI schema at `resources/schemas/settings/search.json` with fields:
- Active provider (dropdown — populated from registered providers + 'text')
- Index on save toggle
- Bulk reindex button (triggers `SearchReindexCommand` via job queue)

## MCP tool migration

`SearchCollectionTool` + `SearchCollectionsTool` currently inject `ObjectSearcher` + `IndexFilter` directly. Phase 5 refactors them to inject `SearchService` instead:

```php
// Before
public function __construct(
    private IndexFilter $indexFilter,
    private ObjectSearcher $searcher,
    ...
) {}

// After
public function __construct(
    private SearchService $searchService,
    private CollectionFetcher $collectionFetcher,
    private PersonaContext $personaContext,
    ...
) {}
```

The tools build a `SearchQuery` from their inputs and call `$this->searchService->search($query)`. With no extension registered, behavior is identical to today (`TextSearchProvider` → `IndexSearcher` returns the same results).

This refactor is mechanical but touches both tools' tests.

## Edition strategy

The framework ships free. The built-in `TextSearchProvider` works on all editions.

Extensions declare their own edition gates. For example, the `totalcms/algolia-search` extension would:

1. Declare `EditionFeature::ALGOLIA_SEARCH = 'algolia_search'` (Pro tier)
2. Check the feature in its registration logic — if non-Pro, skip the registration silently
3. Render a "Pro edition required" message in its settings UI

This pattern keeps T3 core unbiased: any free or paid extension can implement a provider; each one decides its own pricing/edition model.

## File layout

```
src/Domain/Search/
    Data/
        SearchQuery.php
        SearchResult.php
    Service/
        SearchProvider.php           # interface
        SearchProviderRegistry.php
        SearchService.php
        TextSearchProvider.php       # built-in default
    Listener/
        ContentChangeListener.php
    Job/
        ReindexJob.php

src/CLI/Command/Search/
    SearchReindexCommand.php

src/Domain/Extension/
    ExtensionContext.php             # MODIFIED — add registerSearchProvider

src/Domain/Mcp/Tool/Content/
    SearchCollectionTool.php          # MODIFIED — use SearchService
    SearchCollectionsTool.php         # MODIFIED — use SearchService

config/
    container.php                     # MODIFIED — wire registry, service, listener
    defaults.php                      # MODIFIED — add $settings['search']
    routes/                           # no route changes; CLI only

resources/schemas/settings/
    search.json                       # NEW — settings UI

resources/translations/
    admin.{6 locales}.php             # NEW — search settings + reindex command
```

## Testing

**Unit tests:**
- `SearchProviderRegistryTest` — register, lookup, active resolution, collision deny
- `SearchServiceTest` — fallback on provider failure, fallback on isAvailable=false, per-schema opt-out routing
- `TextSearchProviderTest` — delegates to IndexSearcher correctly; index/delete are no-ops
- `ContentChangeListenerTest` — listener fires provider.index/delete on events; enqueues ReindexJob on exceptions

**Integration tests:**
- `SearchCollectionToolTest` — existing test continues passing with the new SearchService routing (no behaviour change)
- `SearchExtensionRegistrationTest` — extension can register a provider; SearchService routes to it; uninstalling falls back to text

**CLI test:**
- `SearchReindexCommandTest` — iterates a collection, calls provider.index for each object

## Reference implementation: bundled `algolia-search` extension

The first reference provider ships **bundled in T3 core** at `resources/extensions/totalcms/algolia-search/` — the same pattern as the existing `ab-split` and `geo-redirect` bundled extensions. Bundling (rather than shipping as a separate Composer package) means:

- Algolia integration travels with T3 releases — no separate version coordination
- Customer enables it from the admin Extensions page (zero-friction install)
- Maintained directly by the T3 team
- Signals "this is the canonical reference implementation" — third-party authors can copy the structure

Layout:

```
resources/extensions/totalcms/algolia-search/
    extension.json                # manifest (id, version, edition, capabilities)
    Extension.php                 # entry point — register/boot lifecycle
    Service/
        AlgoliaSearchProvider.php # implements SearchProvider against Algolia API
    icon.png
    README.md
```

Scope (lands in the same Phase 5 work as the T3 core abstraction):
- `AlgoliaSearchProvider` implementing the interface
- Algolia PHP SDK as a Composer dep (`algolia/algoliasearch-client-php`)
- Settings schema declared via `ExtensionContext::registerSettings()` (Algolia App ID + admin API key + search API key)
- `EditionFeature::ALGOLIA_SEARCH` registered as Pro-tier
- Admin UI panel showing connection status + last-indexed timestamp + record count
- Manual bulk reindex action on the settings page (enqueues `ReindexJob` per object)
- Extension lifecycle handles registration:
  ```php
  public function register(ExtensionContext $context): void {
      if (!$context->editionAllows(EditionFeature::ALGOLIA_SEARCH)) {
          return; // Pro-edition gate
      }
      $context->registerSearchProvider(new AlgoliaSearchProvider(...));
  }
  ```
- Tests as bundled-extension fixtures (mirroring `tests/Unit/Bundled/GeoRedirect/`)

Effort estimate: ~2-3 days, landing in the same Phase 5 chunk as the T3 core abstraction (since the bundled extension is part of T3's release).

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Provider's `isAvailable()` health check is slow (called on every search) | Medium | Document the requirement: must return quickly. Providers should cache the check (e.g., 30s TTL via CacheManager). Health-check failures should not block searches — fall back to text. |
| Refactoring MCP search tools breaks existing tests | Medium | The behaviour through `TextSearchProvider` should be byte-equal to current. Run full MCP test suite after refactor; fix any pinned-to-internal-state assertions. |
| Bulk reindex on large collections (100K+ objects) blocks the job queue | Medium | ReindexJob processes a single object; bulk reindex enqueues one job per object. JobQueue throttling already exists; document expected indexing rate per provider. |
| Per-schema opt-out adds confusion to schema editor UI | Low | New optional `search.provider` field; default value works for 99% of cases; advanced operators can override. Document in the schema editor's `mcp` tab. |
| Extension's provider has a memory leak or hangs | Low | Each request creates a fresh provider instance via the container. Hanging providers degrade THAT request via the existing PHP execution timeout; no cross-request contamination. |
| Algolia rate limits during bulk reindex | Low (provider concern) | Algolia extension is responsible for back-pressure; T3 core just enqueues jobs. The extension can throttle itself. |

## Effort summary

- **Phase 5 core** (T3 repository):
  - Domain layer (interfaces + data + registry + service): ~1 day
  - TextSearchProvider wrapping IndexSearcher: ~half day
  - ContentChangeListener + ReindexJob: ~half day
  - MCP tool refactor + tests: ~half day
  - CLI command + tests: ~half day
  - Settings schema + translations: ~quarter day
  - **Total: ~3-4 days**

- **Bundled Algolia extension** (`resources/extensions/totalcms/algolia-search/` in this repo):
  - SearchProvider implementation: ~1 day
  - Settings UI + admin status panel: ~half day
  - Tests + bundled-extension fixtures: ~half day
  - **Total: ~2-3 days**

Total Phase 5 search work (T3 core abstraction + bundled Algolia reference): **~6-7 days in this repo, single release**.

## Future work (post Phase 5)

These items are explicitly OUT OF SCOPE for this Phase 5 sub-project but worth noting:

- **OpenAI embeddings extension** — separate Composer package (NOT bundled), post-Algolia. Includes vector storage layer design (SQLite + sqlite-vec? flat JSON? bring your own DB?). Kept separate to avoid bloating T3 core with provider-specific code.
- **Meilisearch / Typesense / ChromaDB extensions** — separate Composer packages or community contributions, NOT bundled. The bundled Algolia extension is the canonical reference; everything else lives outside core to keep T3 release scope manageable.
- **`totalcms/site-search` extension** (paid) — sitemap-crawling + page-level indexing + polished Stacks UI + analytics. Uses SQLite FTS5 or its own indexed JSON. Consumes the SearchProviderRegistry for the semantic backend OR ships its own.
- **REST API search endpoint** — `GET /api/search?q=...` that routes through `SearchService`. Not in Phase 5 because there's no immediate caller; site-wide search extension may add this.
- **Admin UI global search** — search across collections from a search bar in the admin nav. Future polish work.
- **Per-collection multi-provider routing** — different providers for different collections. Defer until customer demand surfaces.
- **Search analytics** — query log, click-through tracking, popular queries dashboard. Could fold into the Activity Dashboard or be its own thing.

The other two Phase 5 sub-projects from the master plan have their own future specs:
- **MCP prompts** — templated workflows shipped with collections. Separate brainstorm + spec.
- **Performance optimization** — data-driven; awaits real usage data from Phase 1-4. No designable scope yet.
