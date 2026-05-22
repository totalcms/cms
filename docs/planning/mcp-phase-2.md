# T3 MCP Server — Phase 2 Design

**Status:** Design (2026-05-21) — Phase 1 fully landed; this scopes the next ~2 weeks of work.
**Supersedes:** Phase 2 section of `docs/planning/mcp-server.md` (high-level). That doc remains the canonical multi-phase roadmap; this is the detailed Phase 2 spec.
**Related:** `~/.claude/plans/staged-swimming-nebula.md` (Phase 0 + 1 implementation plan, complete).

## Goal

Turn `tcms://` from a working stub (`get_resource` tool routes it) into a first-class MCP resource surface — `resources/list`, `resources/templates/list`, `resources/read`, `resources/subscribe` — plus the cross-cutting items the planning roadmap promised for Phase 2: extension resource hooks, MCP-scoped CORS, and tool-description polish carrying over from Phase 1.

"Every T3 site is an MCP server" was the 3.5 marketing claim; Phase 1 delivered tools, Phase 2 delivers the resource model alongside.

Phase 2 also adds an MCP surface for **DataViews** — T3's pre-computed cached Twig queries that aggregate across collections. DataViews weren't in the original roadmap doc's Phase 2 section but were identified during design review as a natural fit: a view's result is content (resource-shaped) while its query interface is parameterized (tool-shaped). Both surfaces ship together so agents have the same ergonomics for views as for collections.

## Confirmed decisions

Captured up front so they don't get re-derived inside chunks.

- **Resource model is hybrid** — one collection-level resource (`tcms://{collection}/`) per public collection + one resource template (`tcms://{collection}/{id}`) per public collection. `resources/list` does NOT enumerate individual objects; the template is the agent's signal that the URI pattern exists.
- **Empty `resources/list`** on sites with no public collections returns an empty list, matching `tools/list` behaviour for the public persona. No placeholder entries.
- **Subscriptions are collection-level only** — subscribing to `tcms://blog/` notifies on any blog change. Object-level subscriptions deferred indefinitely; the implementation extends cleanly later.
- **Notifications coalesce per session per URI in a 1-second window** — prevents notify storms during admin sweeps that still fire individual `object.*` events.
- **Import-time events do not trigger notifications** — uses existing `EventDispatcher::suspendForImport()`. Bulk imports don't generate notification floods. A potential post-import "collection changed" notification is future polish, not Phase 2.
- **Subscriptions live in the SessionStore** — per-session list of subscribed URIs. Dies with the session. No new persistent store.
- **CORS is endpoint-global** — reads `mcp.allowedOrigins` config (already in Phase 0 defaults). Per-token CORS layers on at Phase 4.
- **Extensions can use their own URI scheme** — not restricted to `tcms://`. T3 enforces only `scheme://...` shape and per-resource collision policy (strict deny, matches tool model).
- **DataViews get both a tool surface and a resource surface** — `list_views`, `query_view`, `get_view`, `describe_view` tools plus `tcms://view/{id}` resource template. Mirrors the collection surface so agents have one consistent model.
- **DataView subscriptions are per-view** — subscribers register against `tcms://view/{id}`, not a collection-level URI. Bounded exception to the collection-only subscription rule, justified by views being independent named surfaces rather than rows in a collection. The existing rebuild path already fires `object.updated` on the `dataviews` object → listener gains a small branch to fire `tcms://view/{viewId}` instead of `tcms://dataviews/`.
- **`view` and `views` reserved as collection names** — added to `SchemaData::RESERVED_SCHEMAS` so a customer can't create a collection called `view` that would collide with the `tcms://view/{id}` URI namespace. Cheap one-line guarantee that the URI namespace stays clean by construction.

## Architecture

### Resource registry

New `src/Domain/Mcp/Service/ResourceRegistry.php` — an analog of `ToolRegistry`. Holds three collections:

- **Static resources** — concrete URIs like `tcms://blog/`. One per public collection (auto-registered) + any from extensions.
- **Resource templates** — URI templates like `tcms://{collection}/{id}`. One per public collection.
- **Access metadata** — each resource records its `access` level (`admin` or `public`) so the registry can persona-filter at `resources/list` and `resources/templates/list` time, the same way `ToolRegistry` does for tools.

`McpServerFactory` calls `$resourceRegistry->forPersona($persona)` after building the tool list, then registers each entry via the SDK's `addResource()` / `addResourceTemplate()` and a wrapping handler that dispatches to the actual T3 logic.

### Resource handlers

Two concrete handlers live in a new `src/Domain/Mcp/Resources/` directory (mirrors `Tools/`).

- **`CollectionResource`** — handles `resources/read` for `tcms://{collection}/`. Returns a paginated list of recent items (default 50, capped at 50 — same response-size guardrail as `query_collection`). Output is a JSON resource content block with `{name, id, url, summary}` per item, plus a "refine via `query_collection({name})`" pointer in the resource's text. Public persona's draft filter applies.
- **`CollectionObjectResource`** — handles `resources/read` for the template `tcms://{collection}/{id}`. Routes to the existing `GetObjectTool::handler()` so the persona check, field-expose stripping, and markdown rendering logic stay in one place.

`GetResourceTool` keeps existing behaviour (Phase 1 stub still useful for explicit URI fetches in tool flows) but its internals delegate to the same code path that `CollectionObjectResource` uses, so there's no parallel implementation to maintain.

### Subscriptions

`McpResourceSubscriptionManager` (new service) owns the subscription state:

- `subscribe(string $sessionId, string $uri): void`
- `unsubscribe(string $sessionId, string $uri): void`
- `subscribersFor(string $uri): list<string>` (returns session IDs)
- `clearSession(string $sessionId): void` (called on session expiry)

Storage: subscriptions are appended to the existing session record stored via `SessionStoreInterface` — under a `subscriptions` key on the session payload. Per-session state is small (a handful of URIs at most), so the addition is negligible. (Alternative: separate `tmp/mcp-sessions/{sessionId}.subscriptions.json` file. Decision pinned at Chunk B1 after reading the current session record shape; default is "append to session record" unless the existing serialization makes that awkward.)

**Event wiring** — new `src/Domain/Event/Listener/McpResourceSubscriptionListener.php`:

- Subscribes to `object.created`, `object.updated`, `object.deleted`.
- For each event, computes the affected collection URI (`tcms://{collection}/`).
- Looks up subscribers via `subscribersFor()`.
- Pushes a `notifications/resources/updated` JSON-RPC notification to each matching session via the SDK's notification path.
- Applies coalescing: per `(sessionId, uri)`, track the last-notified timestamp in an in-memory cache (request-lifetime, since the listener runs in the same request that triggered the event). If the previous notification for this pair was within 1 second, drop the current one.

**Import-time suspension** — the listener registers via `EventDispatcher::addListener()` on the same `object.*` channel that already gets suspended during imports. No new code; existing suspension mechanism handles it.

### Extension resource hooks

`ExtensionContext::registerMcpResource()` already exists from Phase 1 (declared, not wired). Phase 2 completes the wiring:

- `McpExtensionRegistrar` gains a `registerResources()` path mirroring its existing tool path
- Strict-deny collision policy: an extension whose URI collides with a core resource or another extension's resource is logged + skipped
- Extensions can register either concrete resources or resource templates — same surface as core
- The registrar calls `ResourceRegistry::register()` so extension and core resources share the persona-filtering and dispatch logic

### CORS middleware

New `src/Middleware/Security/McpCorsMiddleware.php`, modeled on `ExternalCorsMiddleware`:

- Reads `mcp.allowedOrigins` (array<string>) from config. Empty array → no CORS headers (browser blocks — default secure).
- Wildcard `'*'` supported for fully-public sites.
- Echoes `Access-Control-Allow-Origin` matching the request `Origin` if it's in the allowlist (or `*` is configured).
- Handles preflight `OPTIONS` requests with appropriate `Allow-Methods`/`Allow-Headers` (`POST, GET, OPTIONS` and `Content-Type, Authorization, X-API-Key`).
- Mounted on `/mcp` and `/.well-known/mcp.json` routes only — not global.

Per-token CORS layers on at Phase 4 — the same middleware will read scoped origins from request context (already set by `McpAuth`) when OAuth tokens land.

### Tool description polish

Cross-cutting cleanup carrying over from Phase 1's "improved auto-tool descriptions" item in the roadmap. Bulk of this was actually delivered in Phase 1 Chunk B (dynamic field catalog) — Phase 2 polish:

- Add `outputSchema` to `query_collection`, `get_collection`, `describe_collection`, `list_collections`. Typed output schemas help SDK-aware clients validate before passing results to LLMs.
- Verify `describe_collection` surfaces property-level `mcp.description` from `McpDescriptionResolver` in its output (Phase 1 added the resolver; this confirms it's threaded through).
- Audit `get_resource` description for accuracy now that resources are first-class (it currently says "Phase 1 entry point" — that comment goes).

Small chunk, mostly verification.

### DataView MCP surface

DataViews are reserved-collection objects in `dataviews` with a Twig `definition` that aggregates across collections. `DataViewBuilder::buildView()` executes the definition and caches JSON output; `DataViewQueryService::query()` exposes the cached result through the same `QueryPipeline` (filter/sort/search/paginate) as collections. Pro+ edition-gated, same as MCP itself.

**Per-view MCP config.** Each view object gets the same `mcp` block customers already configure on collections (Phase 1 Chunk A pattern):

```json
{
  "id": "monthly-sales-summary",
  "definition": "{# ... twig ... #}",
  "mcp": {
    "access": "admin",
    "description": "Aggregate sales by month for the last 12 months.",
    "resource": true
  }
}
```

Schema editor changes are minimal — the `dataviews` schema gains the same `mcp` card via the existing card+schemaref pattern (`mcp-collection.json` from Phase 1 is reusable since the fields are identical). Customers configure per-view access in the existing view editor without a new tab.

**Tools.** Four new tools mirror the collection surface:

- `list_views()` → catalog of views the persona can see. Returns `{id, name, description, last_built, item_count?}` per view.
- `query_view(id, include?, exclude?, sort?, limit?, offset?, format?)` → paginated, REST-syntax filtering. Wraps `DataViewQueryService::query()`. `limit` capped at 50.
- `get_view(id, format?)` → full result. Capped at 50 items in response; if the view has more, returns the first 50 plus a "use `query_view` to paginate" pointer.
- `describe_view(id)` → view metadata (name, description, `last_built`, definition snippet for admin persona only) plus inferred output shape (keys of the first item, sampled). Dynamic since views don't have a fixed schema.

Persona enforcement happens in each tool handler by checking `mcp.access` on the requested view. Public persona sees only views marked `access: 'public'`; admin sees all.

**Resources.** For each view with `mcp.access !== 'admin'` AND `mcp.resource: true`, register `tcms://view/{id}` as a concrete resource. Plus one resource template `tcms://view/{id}` covering all admin views so the admin persona's `resources/templates/list` is complete. `resources/read tcms://view/foo` dispatches to the same code path as `get_view(id: "foo")`.

No `tcms://view/` collection-level resource — the entry point for discovering views is `list_views`, and a flat URI like `tcms://view/` would conflict with the per-view template.

**Subscriptions.** `McpResourceSubscriptionListener` already receives `object.updated` events for all collections. Branch logic: if the event collection is `dataviews`, fire `notifications/resources/updated` for `tcms://view/{objectId}` (using the dataview object's ID as the view ID), rather than `tcms://dataviews/`. Subscribers to `tcms://view/{id}` get notified each time `DataViewBuilder::buildView()` completes successfully — which already calls `objectUpdater->updateObject()` to write the `lastBuilt` timestamp.

Coalescing applies the same way: per `(sessionId, tcms://view/{id})`, drop notifications within a 1-second window of the previous one.

**Output shape risk.** Unlike collections, view output is freeform — the Twig definition can produce any JSON structure. `describe_view` mitigates by sampling the first item's keys; tools tolerate missing keys without erroring. The `outputSchema` on `query_view`/`get_view` declares the wrapping shape (`{items: [...], total, page}`) but the item shape inside is `additionalProperties: true`.

## Chunks

Dependency-ordered. Each chunk closes with PHPStan Level 8 + targeted Pest passes; full suite at chunk F.

### Chunk A — ResourceRegistry + collection-level resources (~2 days)

Foundation. Everything downstream depends on the registry existing.

- **A1.** New `src/Domain/Mcp/Service/ResourceRegistry.php`. Mirrors `ToolRegistry`: in-memory store, persona-filtered accessors (`forPersona(McpPersona)`, `templatesForPersona(McpPersona)`).
- **A2.** New `src/Domain/Mcp/Data/McpResourceDefinition.php` — value object with `uri`, `name`, `description`, `mimeType`, `access`, `handler`. Mirrors `McpToolDefinition`.
- **A3.** New `src/Domain/Mcp/Data/McpResourceTemplateDefinition.php` — value object with `uriTemplate`, `name`, `description`, `mimeType`, `access`, `handler`.
- **A4.** New `src/Domain/Mcp/Resources/CollectionResource.php` and `CollectionObjectResource.php` — concrete handlers per the architecture section.
- **A5.** New `src/Domain/Mcp/Service/CollectionResourceRegistrar.php` — at container build time, iterates all collections. Two registration rules:
  - Public collections (`mcp.access === 'public'` AND `mcp.resource: true`): register one concrete resource (`tcms://{collection}/`) and one resource template (`tcms://{collection}/{id}`) with `access: 'public'`.
  - Admin-only collections (`mcp.access === 'admin'` AND `mcp.resource: true`): same two registrations but with `access: 'admin'` so the admin persona's `resources/list` is fully populated. `mcp.resource: true` is the Phase 1 default; the resource block is opt-out at the collection level.
  - `'authenticated'` is reserved for Phase 4 — registrar accepts it as a synonym for `'admin'` in 3.5 (no third persona to expose it to yet).
- **A6.** Modify `McpServerFactory` to call `addResource()`/`addResourceTemplate()` for each persona-visible registry entry, exactly as it already does for tools.
- **A7.** Modify `GetResourceTool` to dispatch via the registry's templates rather than hand-rolled URI parsing — so adding a new URI scheme (extensions) automatically works in `get_resource` too.
- **A8.** Update server `setInstructions()` to mention resources alongside tools.
- **A9.** Pest tests: registry persona filtering; collection-level resource read returns recent items; template resource read returns single object; admin-only collections invisible to public persona; field `expose: false` strips fields from resource output too.

### Chunk B — Subscriptions (~2 days)

The trickier piece — needs persistent state + event-driven push.

- **B1.** New `src/Domain/Mcp/Service/McpResourceSubscriptionManager.php` with the API described above. Storage layered on `SessionStoreInterface`.
- **B2.** New `src/Domain/Event/Listener/McpResourceSubscriptionListener.php`. Listens for `object.created`, `object.updated`, `object.deleted`. Resolves collection from event payload, looks up subscribers, pushes notifications via SDK's notification path.
- **B3.** Coalescing: in-memory request-scoped cache `(sessionId, uri) → lastTimestamp`. Drop notifications within 1s of the previous one for the same pair. Document the request-scoped lifetime (sub-second coalescing only — appropriate for sweeps that fire many events in one HTTP request; not a long-running debounce).
- **B4.** Register the listener in the DI container against `EventDispatcher` so it auto-wires at boot.
- **B5.** Verify `EventDispatcher::suspendForImport()` already covers our listener — same event channels, no extra code needed. Add a Pest test that confirms no notifications fire during a suspended import.
- **B6.** Wire `resources/subscribe` and `resources/unsubscribe` request handlers through the SDK. `Mcp\Server\Builder` advertises `resourcesSubscribe` capability automatically when resources are present (verified in Phase 0 SDK probe). The SDK's request-handler registry surfaces subscribe/unsubscribe to T3 — concrete API call confirmed in the chunk's opening spike. Subscription manager is the backing store; the SDK handles the JSON-RPC framing.
- **B6a (spike).** Half-day spike at the head of chunk B: connect MCP Inspector to a Phase 1 endpoint, subscribe to a dummy resource, fire a notification manually via the SDK's notification path, confirm Inspector receives it. Outcome of the spike pins the exact API surface used in B2 and B6, and confirms cross-request push (notifying from `object.*` event-handler request → another session's stream) actually works. If the SDK can't push outside the originating request, chunk B degrades to a polling-only stub and full push moves to a 3.5.x point release.
- **B7.** Add `mcp.subscriptionsEnabled` (default `true`) to config — operator kill switch in case subscriptions cause grief in production. Setting to `false` makes the server advertise no subscription capability and rejects subscribe requests with a clear error.
- **B8.** Pest tests: subscribe + change object + assert notification fired; coalescing actually drops duplicates within 1s; unsubscribe stops notifications; session expiry clears subscriptions; import-time suspension verified.

### Chunk C — Extension resource hooks (~1 day)

- **C1.** Modify `src/Domain/Extension/Service/McpExtensionRegistrar.php` to add a `registerResources()` method paralleling its existing tool-registration path.
- **C2.** Add `ExtensionContext::registerMcpResourceTemplate(string $uriTemplate, string $name, string $description, \Closure $handler, string $access = 'public'): void` as a parallel method to the existing `registerMcpResource()` — matches the SDK's split between `addResource()` and `addResourceTemplate()` and keeps each method's signature focused. Update `registerMcpResource()`'s docblock to drop the "Phase 2 will…" language and document the now-live wiring.
- **C3.** Collision policy: when `ResourceRegistry::register()` sees a URI collision (or template collision), log a warning identifying the extension whose registration was rejected. Same UX as tool collisions.
- **C4.** Add a fixture extension in `tests/fixtures/extensions/` that registers a custom-scheme resource (e.g. `acme://invoices/`); Pest test verifies it shows in `resources/list` for admin persona.

### Chunk D — MCP CORS middleware (~1 day)

- **D1.** New `src/Middleware/Security/McpCorsMiddleware.php`. Reads `Config::$mcp['allowedOrigins']`.
- **D2.** Empty allowlist → no CORS headers emitted. `'*'` in list → echo request `Origin` (or `*` if no Origin sent). Specific origins → echo origin if listed, else no header.
- **D3.** Preflight `OPTIONS` handling: respond 204 with `Access-Control-Allow-Methods: POST, GET, OPTIONS`, `Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key`, `Access-Control-Max-Age: 86400`.
- **D4.** Mount in `config/routes/public/mcp.php` on `/mcp` and `/.well-known/mcp.json` route groups. Verify ordering relative to existing middleware (CORS must run before auth so preflights aren't blocked by missing tokens).
- **D5.** Add MCP CORS settings field to `resources/schemas/settings/mcp.json` — text array input for origins. Update locale files (en_US through nl_NL plus af and sr-Latin/Cyrl per recent commit).
- **D6.** Pest feature tests: preflight returns 204 with right headers; allowed origin echoed back; disallowed origin gets no CORS header; wildcard mode works.

### Chunk E — Tool description polish (~1 day)

- **E1.** Add `outputSchema` to `QueryCollectionTool`, `GetObjectTool`, `DescribeCollectionTool`, `ListCollectionsTool`. Each describes the shape of the returned `content` payload — typed result for SDK-aware clients.
- **E2.** Verify `DescribeCollectionTool` output includes property-level `mcp.description` from `McpDescriptionResolver`. If not, thread it through. Add a Pest assertion confirming a property's resolved description ends up in the tool's output.
- **E3.** Remove stale "Phase 1 entry point" / "Phase 2 will…" language from `GetResourceTool` and the comment in `ExtensionContext::registerMcpResource()`. Replace with current status.
- **E4.** Run `tcms mcp:test` against each polished tool to sanity-check the new output schema renders correctly through the SDK.

### Chunk F — DataView MCP surface (~2 days)

- **F1.** Reserve `view` and `views` in `src/Domain/Schema/Data/SchemaData::RESERVED_SCHEMAS` so neither can be created as a collection by customers. Add a Pest assertion confirming the reservation; verify `SchemaSaver` rejects attempts to create either.
- **F2.** Add the `mcp` card to the `dataviews` schema (or wherever `DataView` objects are validated). Reuse `mcp-collection.json` from Phase 1 — same three fields (`access`, `description`, `resource`), same UI affordance. No new schema file; just a card wire-up in the dataviews schema.
- **F3.** New `src/Domain/Mcp/Tools/Content/ListViewsTool.php`, `QueryViewTool.php`, `GetViewTool.php`, `DescribeViewTool.php`. Each registers itself with `ToolRegistry`. `access: 'public'` on all four (persona check happens per-view inside the handler based on the requested view's `mcp.access`).
- **F4.** `QueryViewTool` wraps `DataViewQueryService::query()`, mapping the MCP input params (`include`, `exclude`, `sort`, `limit`, `offset`) to the existing REST-syntax param shape. Same `limit ≤ 50` guardrail. Format param routes through `ContentRenderer`.
- **F5.** `GetViewTool` calls `DataViewFetcher::getViewData()`. If the fetched data exceeds 50 items, truncate and append a "showing N of M — call `query_view` to paginate" note in the response text.
- **F6.** `DescribeViewTool` returns view metadata from `DataViewLister` + a sampled output-shape block. For admin persona, include the view's `definition` field (so AI dev workflows can read the Twig). For public persona, omit the definition.
- **F7.** New `src/Domain/Mcp/Service/DataViewResourceRegistrar.php` — analog of `CollectionResourceRegistrar` for views. Iterates the `dataviews` collection at container build time; for each view with `mcp.access !== 'admin'` AND `mcp.resource: true`, register `tcms://view/{id}` as a concrete resource. Add the resource template `tcms://view/{id}` once at registry init (admin persona scope so it always shows up in `resources/templates/list`).
- **F8.** Update `McpResourceSubscriptionListener` (from Chunk B) to branch on event collection: when collection is `dataviews`, fire notifications for `tcms://view/{objectId}`. All other collections continue to fire `tcms://{collection}/`. Same coalescing logic applies.
- **F9.** Optional polish — add `cms.view.list()` to surface `mcp.access` in its response so customers building list UIs can show which views are MCP-exposed. Not load-bearing; defer to 3.5.x if it pushes the chunk over budget.
- **F10.** Pest tests: persona filtering per view; `tcms://view/{id}` resource read returns view data; subscription fires on `buildView()` completion; `view`/`views` reservation prevents collection creation; freeform output shape doesn't crash `describe_view` when sampling.

### Chunk G — Tests + docs + verification (~2 days)

- **G1.** Pest cross-chunk feature tests: full `resources/list` + `resources/read` + `resources/subscribe` round-trip via the Slim app; admin persona vs public persona visibility; subscription notifications received over a fake transport. Include view-tool + `tcms://view/{id}` resource coverage.
- **G2.** End-to-end smoke with MCP Inspector: mark `blog` as `mcp.access: 'public'` and one view as `mcp.access: 'public'`. Confirm Inspector sees `tcms://blog/` and `tcms://view/{viewId}` in resources, can read each, subscribes, and receives notifications when a post is created/updated/deleted and when a view is rebuilt.
- **G3.** Browser smoke for CORS: from a separate origin, confirm preflight + actual request both succeed when origin allowlisted; both fail when not.
- **G4.** New `resources/docs/operations/mcp-resources.md` documenting the resource model, subscription behaviour, CORS config, extension hooks, and the DataView surface (`list_views`/`query_view`/`get_view`/`describe_view` + `tcms://view/{id}`). Update menu in `resources/docs/menu.php` under "Operations" → "MCP".
- **G5.** Rebuild docs index: `php bin/build-docs-index.php`. Commit the regenerated `resources/docs/search-index.json`.
- **G6.** Run `composer run stan` (must pass Level 8). Run `composer run test`.

## Configuration changes

`config/defaults.php`:

```php
$settings['mcp'] = [
    // existing keys preserved
    'enabled'              => true,
    'publicAccess'         => false,
    'allowedOrigins'       => [],         // Phase 2: wired into McpCorsMiddleware
    'publicIpPerMinute'    => 60,
    'toolPrefix'           => '',
    // new in Phase 2:
    'subscriptionsEnabled' => true,       // operator kill switch
];
```

`resources/schemas/settings/mcp.json` gains:

- `allowedOrigins` — array of origin strings, validation against URL-like shape
- `subscriptionsEnabled` — toggle, default true

Locale keys added: `settings.mcp_allowed_origins_label`, `settings.mcp_allowed_origins_help`, `settings.mcp_subscriptions_enabled_label`, `settings.mcp_subscriptions_enabled_help` — across all locale files including the recently-added af and Serbian Latin/Cyrillic variants.

## Storage shape

Collection-level MCP card already defined by Phase 1 — no schema changes in Phase 2:

```json
{
  "id": "blog",
  "mcp": {
    "access": "public",
    "description": "...",
    "resource": true
  }
}
```

The `resource` flag (default true, set in Phase 1) gates whether the collection appears as a resource. `access` continues to gate the persona who sees it.

DataView objects (in the `dataviews` collection) carry the same `mcp` block on each object — same three fields, same semantics:

```json
{
  "id": "monthly-sales-summary",
  "definition": "{# ... twig ... #}",
  "lastBuilt": "2026-05-21 14:30:00",
  "mcp": {
    "access": "admin",
    "description": "Aggregate sales by month for the last 12 months.",
    "resource": true
  }
}
```

The `dataviews` schema gains the `mcp` card via the same card+schemaref wiring as `resources/schemas/collection.json`, pointing at the existing `mcp-collection.json`.

Subscription state per session (illustrative; exact serialization follows whatever SessionStoreInterface dictates):

```json
{
  "tcms://blog/": { "since": 1716300000 },
  "tcms://products/": { "since": 1716300042 },
  "tcms://view/monthly-sales-summary": { "since": 1716300100 }
}
```

`since` is informational — useful for "what changed since I subscribed" features later; not used in Phase 2 dispatch.

## Source layout (new files)

```
src/
  Domain/Mcp/
    Service/
      ResourceRegistry.php
      CollectionResourceRegistrar.php
      DataViewResourceRegistrar.php
      McpResourceSubscriptionManager.php
    Data/
      McpResourceDefinition.php
      McpResourceTemplateDefinition.php
    Resources/
      CollectionResource.php
      CollectionObjectResource.php
      DataViewResource.php
    Tools/Content/
      ListViewsTool.php
      QueryViewTool.php
      GetViewTool.php
      DescribeViewTool.php
  Domain/Event/Listener/
    McpResourceSubscriptionListener.php
  Middleware/Security/
    McpCorsMiddleware.php
resources/docs/operations/
  mcp-resources.md
tests/Feature/Mcp/
  ResourcesTest.php
  SubscriptionsTest.php
  CorsTest.php
  DataViewToolsTest.php
tests/Unit/Mcp/
  ResourceRegistryTest.php
  McpResourceSubscriptionManagerTest.php
  CollectionResourceTest.php
  CollectionObjectResourceTest.php
  DataViewResourceTest.php
tests/fixtures/extensions/<vendor>/<name>/
  (new extension fixture registering a custom-scheme resource)
```

## Source layout (modifications)

- `src/Domain/Mcp/Service/McpServerFactory.php` — call `addResource()`/`addResourceTemplate()` per persona; reference subscription capability flag from config.
- `src/Domain/Mcp/Tools/Content/GetResourceTool.php` — delegate to registry-based dispatch; description cleanup.
- `src/Domain/Mcp/Tools/Content/QueryCollectionTool.php`, `GetObjectTool.php` — `outputSchema` addition.
- `src/Domain/Mcp/Tools/Discovery/DescribeCollectionTool.php`, `ListCollectionsTool.php` — `outputSchema` + verify property `mcp.description` surfacing.
- `src/Domain/Extension/ExtensionContext.php` — update `registerMcpResource()` docblock; add `registerMcpResourceTemplate()`.
- `src/Domain/Extension/Service/McpExtensionRegistrar.php` — wire resource registration through `ResourceRegistry`.
- `src/Domain/Schema/Data/SchemaData.php` — add `view` and `views` to `RESERVED_SCHEMAS`.
- `src/Domain/DataView/Service/DataViewLister.php` — extend list shape to include `mcp` metadata (powers `list_views` + optional `cms.view.list()` polish in F9).
- `config/container.php` — register `ResourceRegistry`, `CollectionResourceRegistrar`, `DataViewResourceRegistrar`, `McpResourceSubscriptionManager`, the listener, the middleware, and the four new DataView tools.
- `config/routes/public/mcp.php` — attach `McpCorsMiddleware`.
- `config/defaults.php` — add `subscriptionsEnabled`.
- `resources/schemas/settings/mcp.json` — UI fields for `allowedOrigins` and `subscriptionsEnabled`.
- `resources/schemas/dataviews.json` (or wherever the `dataviews` schema lives) — add `mcp` card via `$ref` + `schemaref` to `mcp-collection.json`.
- Locale files — new keys (~8 strings × N locales).
- `resources/docs/menu.php` — add MCP Resources page.

## Existing T3 infrastructure to reuse

- **SDK resource API:** `Mcp\Server\Builder::addResource()` and `addResourceTemplate()` (Phase 0 confirmed they're available).
- **Persona filtering pattern:** `ToolRegistry::forPersona()` — replicate exactly for `ResourceRegistry`.
- **Field expose stripping:** existing logic in `GetObjectTool` / `QueryCollectionTool` already strips `mcp.fields.{name}.expose: false` — reuse via shared helper if not already extracted.
- **Markdown rendering:** `ContentRenderer` (Phase 1 Chunk F) handles the markdown/html/text format param — passes through unchanged.
- **EventDispatcher:** wire listener via existing `EventDispatcher::addListener()`; `suspendForImport()` already handles bulk-import suppression.
- **SessionStore:** `SessionStoreInterface` — extend with subscription storage. No new persistence layer.
- **CORS pattern:** `ExternalCorsMiddleware` — copy and adapt for MCP-scoped behaviour.
- **Settings UI:** `resources/schemas/settings/mcp.json` extended via existing card+schemaref pattern.
- **DataView services:** `DataViewQueryService::query()` for paginated queries; `DataViewFetcher::getViewData()` for full reads; `DataViewLister::listViews()` for the catalog; `DataViewBuilder::buildView()` already triggers `object.updated` for `dataviews` which feeds the subscription listener — zero changes needed inside DataView domain code.
- **Reserved schemas:** `SchemaData::RESERVED_SCHEMAS` constant — add `view` + `views` so the URI namespace stays clean.
- **Existing MCP schema files:** `mcp-collection.json` from Phase 1 is reusable as-is for the dataview `mcp` card (same `access`/`description`/`resource` fields). No new schema file needed for views.

## Key risks

1. **SDK subscription API surface.** `mcp/sdk ^0.5.0` advertises subscription support but Phase 0 didn't exercise it end-to-end. First task in Chunk B is a spike: connect MCP Inspector, subscribe to a resource, manually fire a notification, confirm the inspector receives it. If the SDK requires more glue than expected, scope the spike and adjust the chunk estimate.
2. **Notification dispatch path.** Notifications are server-pushed; the request that fires an `object.*` event isn't the request that owns the subscriber's session. The SDK has to support sending notifications outside the request that's currently being served — verify in the spike. Mitigation if push isn't viable: degrade to polling (clients call `resources/read` periodically) and ship subscriptions as a no-op stub in Phase 2, full push in a 3.5.x point release.
3. **Coalescing scope.** Request-scoped coalescing only catches storms within one PHP request. Cross-request storms (an admin script calling the API repeatedly) get one notification per request. Acceptable for Phase 2; document as a known limit.
4. **Subscription storage churn.** Per-session JSON files in `tmp/mcp-sessions/` could accumulate. Reuse existing session-cleanup mechanism (cron / session-expiry hook) to prune. Verify the cleanup hook actually runs in production deployments.
5. **CORS misconfiguration footgun.** Wildcard `'*'` opens the site to any browser-based AI client. Settings UI help text needs strong language ("only set if you understand the implications").
6. **DataView output is freeform.** Unlike collections, a view's output shape depends on its Twig definition. `describe_view` mitigates by sampling keys from the first item; tools tolerate missing keys without erroring. `query_view`/`get_view` `outputSchema` declares only the wrapping shape (`items[]`, `total`, pagination) with `additionalProperties: true` on items. AI agents may need to call `describe_view` before composing useful filters on an unfamiliar view.
7. **`view` reservation may collide with existing customer installs.** A live site that already created a `view` collection would fail on next `SchemaSaver` validation pass. Mitigation: do a quick grep across the test sites + customer demo data to confirm zero existing usage; document the reservation in 3.5 upgrade notes; if any production site has one, write a one-line migration that renames the collection. Likely a non-issue given how niche the name is, but worth verifying before shipping.

## Verification

- **Pest unit + feature suite passes**, including new tests for ResourceRegistry, subscription manager, CORS middleware, each new resource handler, the four DataView tools, and the `view`/`views` reservation.
- **PHPStan Level 8 clean** after each chunk.
- **MCP Inspector smoke**: from a clean session, `resources/list` returns expected collections; `resources/read tcms://blog/` returns recent items; subscribe, mutate a post in admin, confirm notification arrives. Same flow for `tcms://view/{viewId}` — rebuild the view (via admin or `tcms` CLI) and confirm a notification fires.
- **DataView tool smoke**: from a clean admin session, `list_views` returns the catalog; `query_view({id})` paginates correctly; `describe_view` returns the sampled output shape; `get_view` caps at 50 items and emits the truncation pointer when the underlying data is larger.
- **Browser CORS smoke**: from a separate-origin HTML page, fetch `/mcp` with preflight — succeeds when origin in allowlist, fails when not.
- **Persona isolation smoke**: with public API connection (no key), confirm only public collections AND public views appear in `resources/list`; admin connection sees everything.
- **Reservation smoke**: try to create a collection named `view` via `tcms collection:create view` or the admin UI — confirm `SchemaSaver` rejects it.
- **Extension fixture smoke**: load the fixture extension; confirm its custom-scheme resource appears in `resources/list` for admin persona.

## Anthropic Directory readiness items added in Phase 2

(Phase 1 covered the tool-side checklist; Phase 2 adds the resource-side equivalents.)

- [ ] Every resource has a clear name and description.
- [ ] Resource templates use the `{name}` placeholder convention.
- [ ] `resources/list` is paginated (SDK handles this) and bounded to a reasonable page size.
- [ ] Subscription notifications include the URI that changed and a change-type hint (`created` / `updated` / `deleted`).
- [ ] CORS is opt-in, default deny.
- [ ] No resource exposes admin-only data to a public persona.

## Out of scope (deferred)

- **Object-level subscriptions** — collection-level only in 3.5. Add if customer feedback shows agents want fine-grained subscriptions.
- **Cross-request notification coalescing** — request-scoped is sufficient for typical event patterns; cross-request debouncing adds complexity for marginal gain.
- **Post-import "collection changed" notification** — would let agents know "blog had a 5000-post import, refresh your view." Worth considering as a 3.5.x add if subscriptions get used.
- **OAuth / scoped tokens** — Phase 4.
- **Per-token CORS configuration** — Phase 4 (same middleware, layered scope).
- **Per-token rate limits** — Phase 4.
- **SSE streaming for large resource reads** — Phase 3.
- **MCP prompts** — Phase 5+ (noted in the main planning doc as worth pull-forward consideration if a T3 workflow naturally fits; not committed in Phase 2).
- **Semantic search providers** — Phase 5.
- **Custom schema-defined tools** — Phase 3.

## Effort summary

| Chunk | Effort | Description |
|---|---|---|
| A. ResourceRegistry + collection resources | ~2 days | Registry + handlers + collection auto-registrar + SDK wiring |
| B. Subscriptions | ~2 days | Manager + listener + coalescing + SDK subscribe hooks + kill switch |
| C. Extension resource hooks | ~1 day | Complete `registerMcpResource()` wiring; fixture extension |
| D. CORS middleware | ~1 day | New middleware + settings UI field + preflight handling |
| E. Tool description polish | ~1 day | `outputSchema`; verify property `mcp.description` surfacing |
| F. DataView MCP surface | ~2 days | `view`/`views` reservation; view tools (list/query/get/describe); `tcms://view/{id}` resources; per-view subscription branch |
| G. Tests + docs + verification | ~2 days | Pest suite + Inspector smoke + new docs page + index rebuild |
| **Total** | **~11 days (~2 weeks)** | One day over the original Phase 2 estimate; DataView additions added during design review |
