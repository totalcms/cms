# T3 MCP Server — Phase 3 Design

**Status:** Design (2026-05-23) — Phase 2 fully landed; this scopes the next ~1.5 weeks of work.
**Supersedes:** Phase 3 section of `docs/planning/mcp-server.md` (high-level). That doc remains the canonical multi-phase roadmap; this is the detailed Phase 3 spec.
**Related:** `docs/planning/mcp-phase-2.md` (Phase 2 spec, complete); `~/.claude/plans/staged-swimming-nebula.md` (Phase 0 + 1 implementation plan, complete).

## Goal

Phase 1 shipped the core tool surface. Phase 2 shipped the resource model. Phase 3 closes out the three remaining roadmap bullets:

1. **Schema-defined custom tools** — customers declare parameterized saved-query tools in a collection's `mcp` card. No PHP required; the tools are auto-registered at server-build time and dispatch through the same `QueryPipeline` and persona-filtered `ToolRegistry` as the core content tools.
2. **SSE / progress-notification pattern** — the SDK's `StreamableHttpTransport` already auto-switches `/mcp` to SSE when a tool's handler suspends a Fiber. Phase 3 verifies the path end-to-end, retrofits one slow admin tool (`schema_create`) with `ClientGateway::sendNotification()` calls to demonstrate the pattern, and documents it for extension authors.
3. **Extension-starter worked example + docs** — a sub-extension in `totalcms/extension-starter` that registers one custom tool + one custom resource, paired with a new T3 docs page that walks through both patterns plus the progress-notification pattern from item 2.

Schema-defined tools ship **parameterized from day one** (v2): the JSON definition carries a `params` block, callers supply typed arguments, filter values can reference `{{params.X}}` placeholders. Fixed-filter tools (no params) are a degenerate case of the same machinery — one code path, not two.

After Phase 3, T3 customers without PHP skills can publish AI-ready saved-query tools alongside their collections, extension authors have the full MCP toolkit on `ExtensionContext` (tools, resources, resource templates — all shipped in earlier phases), and the existing extension-starter repo demonstrates the pattern.

## Confirmed decisions

Captured up front so they don't get re-derived inside chunks.

- **Schema tools live in the existing `mcp` card on each collection** — `resources/schemas/mcp-collection.json` (Phase 1 A1) gains an optional `tools: array<mcp-tool>` field. Tools are scoped to their parent collection — no cross-collection schema tools in v1.
- **Tools are parameterized from day one** — the v2 path. JSON definition carries a `params` block; `FilterValueResolver` substitutes `{{params.X}}` in filter values; `SavedQueryToolFactory` builds a dynamic-signature closure so the SDK's reflection picks up the caller args. Fixed tools (empty `params`) work through the same machinery.
- **Persona inheritance, not override** — a schema tool's `access` is its parent collection's `mcp.access`. A schema tool can't elevate above its collection, and there is no per-tool `access` override. Prevents "public tool on an admin-only collection" foot-guns.
- **Strict-deny collision policy** — schema tool names that collide with a core tool (`query_collection`, `get_collection`, …), an extension-registered tool, or another schema tool (in any collection) are rejected at registration. Logged at WARN; the offending tool is skipped; the server still boots.
- **Schema tools inherit Phase 1's response-size guardrails** — `limit` ≤ 50, oversize results return a truncation hint. A definition with `limit: 1000` is silently clamped + logged at WARN.
- **Streaming work in Phase 3 is verification + pattern documentation, NOT response-body chunking** — MCP protocol's "streaming" surface is server→client messages during a tool call (progress notifications, sampling, logging). The SDK auto-switches to SSE when a Fiber suspends. We verify the path works E2E, retrofit one admin tool as a worked example, and document the pattern. Response-size mitigation for big result sets is Phase 1's existing `limit ≤ 50` cap + truncation hints.
- **Admin UI is a JSON textarea on the existing MCP tab** — no repeater of structured form fields. Customer pastes a JSON array; `SchemaValidator` enforces shape against `mcp-tool.json`. Power-user UX for v1; richer form builder is deferred to a 3.5.x point release if customer feedback warrants it.
- **`{{params.X}}` is the only placeholder syntax recognized** — any other `{{...}}` pattern in a filter value is treated as a literal. Validator emits a warning if `{{...}}` is present without a matching declared param so customers can't ship tools that look templated but aren't.
- **Reuse `mcp-tool.json` for both schema-defined and extension-registered tools' validation** — extension `registerMcpTool()` calls already pass an `inputSchema` array. Schema-defined tools generate `inputSchema` from their `params` block. The two share no storage but converge on the same SDK `addTool()` shape, so divergence at the registry layer is minimal.
- **Schema tool changes invalidate sessions** — saving a schema with `mcp.tools` changes invokes `McpSessionInvalidator::invalidateAll()` (Phase 1 G5 service). Already wired via the `schema.saved` event listener.

## Architecture

### Tool registry path (refresher from Phase 1/2)

The MCP tool surface is built per request — `McpEndpointAction` resolves the persona, then calls `McpServerFactory::build($persona)` which:

1. Walks `ToolRegistry::forPersona()` — the persona-filtered list of `McpToolDefinition` entries pre-registered at container build time (core tools + extension tools).
2. Walks `ResourceRegistry::forPersona()` and `templatesForPersona()` — Phase 2's resource surface.
3. For each entry, calls `Mcp\Server\Builder::addTool()` / `addResource()` / `addResourceTemplate()`.

Phase 3 inserts a new step **between (1) and (2)**: a `SchemaToolRegistrar` that walks all `CollectionData`, materializes a `SavedQueryTool` per `mcp.tools` entry, and registers each into the same `ToolRegistry` before `forPersona()` is consumed by the factory.

### Schema tool definition shape

`resources/schemas/mcp-tool.json` defines a single tool entry:

```json
{
	"name":        "find_listings",
	"description": "Search active real estate listings by city and max price.",
	"params": {
		"city":      { "type": "string", "description": "City name (case-insensitive substring match).", "required": true },
		"max_price": { "type": "number", "description": "Maximum price in USD.", "minimum": 0 }
	},
	"filters": {
		"status": { "value": "active" },
		"city":   { "operator": "contains", "value": "{{params.city}}" },
		"price":  { "operator": "lte",      "value": "{{params.max_price}}" }
	},
	"sort":   "price:asc",
	"limit":  20,
	"format": "markdown"
}
```

Field semantics:

- `name` — required. `^[a-z][a-z0-9_]*$`, max 64 chars (including any `mcp.toolPrefix`). Globally unique across all tools (core + extension + schema).
- `description` — required. Max 1024 chars. The base description; the factory appends an auto-built parameter block at render time.
- `params` — optional object. Keys are snake_case param names; values are `{type, description?, required?, default?, enum?, minimum?, maximum?, format?}`. Param `type` ∈ `string|number|integer|boolean`. Nested objects and arrays are out of scope for v1.
- `filters` — optional object. Keys are field names from the collection's schema; values are `{value, operator?}`. `value` is either a literal or a string containing `{{params.X}}` placeholders. `operator` ∈ `eq|ne|lt|lte|gt|gte|contains|starts|ends|in|notin` (default `eq`).
- `sort` — optional REST-style string (`fieldname:asc|desc`, comma-separated).
- `limit` — optional integer, default 20, clamped to 50.
- `offset` — optional integer, default 0.
- `include`, `exclude` — optional REST-style filter strings; escape hatch for filter shapes the structured `filters` block can't express. Substituted after `filters` is merged.
- `format` — optional, one of `markdown|html|text`, default `markdown`.

### Component responsibilities

- **`SavedQueryToolDefinition`** (`src/Domain/Mcp/Data/`) — pure value object. Built from a validated JSON entry + the parent collection name. Carries all fields above plus the resolved collection. No behavior.
- **`SavedQueryToolFactory`** (`src/Domain/Mcp/Service/`) — given a definition, builds a `SavedQueryTool` whose handler closure has the right signature. Closures with `params` declare those params as named function parameters so SDK reflection picks them up; closures without `params` have a no-arg signature. Encapsulates `inputSchema` generation from the `params` block. Single point where dynamic closure construction lives.
- **`FilterValueResolver`** (`src/Domain/Mcp/Service/`) — given a filter value (possibly containing `{{params.X}}`) and the resolved arg map plus the param spec, returns the literal value to pass into `QueryPipeline`. Validates that referenced params exist; throws `SavedQueryToolException` upstream for misconfigured definitions (caught by the tool handler, returned as `isError: true`).
- **`SchemaToolRegistrar`** (`src/Domain/Mcp/Service/`) — called once during `McpServerFactory::build()` before `ToolRegistry::forPersona()` is consumed. Walks all collections with non-empty `mcp.tools`, validates each entry, builds via `SavedQueryToolFactory`, registers into `ToolRegistry`. Collision detection lives here.
- **`SavedQueryTool`** (`src/Domain/Mcp/Tool/Schema/`) — runtime tool. Holds the definition + injected dependencies (`ObjectFetcher`, `FilterValueResolver`, `ContentRenderer`, `PersonaContext`, `ObjectUrlBuilder`). Handler receives caller args matching the param spec, resolves filter placeholders, builds REST-style include/exclude strings, queries via `QueryPipeline`, applies persona-aware safety filters, strips non-exposed fields, decorates with URLs, returns paginated result.
- **`SavedQueryToolException`** (`src/Domain/Mcp/Exception/`) — domain exception for definition validation, collisions, and placeholder errors. Caught by handlers; returned as `isError: true` with a recovery hint.

### Generated `inputSchema` shape

For each tool, the factory generates a JSON Schema from the `params` block:

```json
{
	"type": "object",
	"properties": {
		"city":      { "type": "string", "description": "City name (case-insensitive substring match)." },
		"max_price": { "type": "number", "description": "Maximum price in USD.", "minimum": 0 }
	},
	"required": ["city"]
}
```

The SDK enforces this against caller args before dispatch — type mismatches, missing required params, out-of-range numbers all surface as protocol-level errors before `SavedQueryTool::handler` runs.

### Description rendered to AI

The factory composes the tool's `description` string from the user's `description` field + an auto-built parameter block:

```
Search active real estate listings by city and max price.

Parameters:
- city (string, required): City name (case-insensitive substring match).
- max_price (number, optional, ≥ 0): Maximum price in USD.

Returns: paginated listing objects with url per item.
```

Matches Phase 1's dynamic-description pattern. Tools with no `params` get a parameter-less description (just the user's text + the "Returns:" line).

### Streaming / progress notifications

`Mcp\Server\Transport\StreamableHttpTransport` (vendor/mcp/sdk) auto-switches `/mcp` to SSE when a tool handler suspends its Fiber. The SDK's `ClientGateway::sendNotification()` is the way a tool sends server→client messages mid-call; calling it from inside a handler is what triggers the SSE switch.

Phase 3 adds:

- One worked example in the existing `SchemaTools::createHandler()` (Phase 1's `schema_create` admin tool — large `jumpstart`-style seeding can take seconds): emit progress notifications via `ClientGateway::sendNotification()` at meaningful checkpoints.
- E2E verification that the SDK actually emits these as SSE events to the client.
- `mcp-extensions.md` docs covering the pattern with code samples.

No new T3 services for streaming. The SDK already does the work; we use it.

### Collision policy

Schema tool registration is strict-deny against three sources, in order:

1. **Core tool name collision** (e.g., a schema tool named `query_collection`) → reject, log WARN, skip.
2. **Schema-vs-schema collision across collections** (e.g., both `blog` and `news` define a tool named `latest`) → reject **both**, log WARN naming both collections. Defensive: neither customer's tool works until they rename one — easier to diagnose than silently picking one.
3. **Schema-vs-extension collision** — extension tools register later in the bootstrap (they're added to `ToolRegistry` during `ExtensionManager::boot()`). When the extension registrar sees a collision against an already-registered schema tool, it logs and skips the extension's tool. Same behavior as the existing core-vs-extension collision policy (`McpExtensionRegistrar`).

Logged collisions are surfaced in the admin via the existing `ExtensionState` warning mechanism (extended to cover schema tool collisions too — small follow-on to the Phase 1 system).

## Chunks

Dependency-ordered. Each chunk closes with PHPStan Level 8 + targeted Pest passes; full suite at chunk F.

### Chunk A — `mcp-tool.json` schema + `mcp-collection.json` extension (~0.5 day)

Foundation. Everything downstream depends on the JSON Schema being in place.

- **A1.** New `resources/schemas/mcp-tool.json` — defines the single-tool entry shape per the "Schema tool definition shape" section above.
- **A2.** Modify `resources/schemas/mcp-collection.json` — add `tools` field as an optional `array<mcp-tool>` via `$ref` to `mcp-tool.json`. Same pattern as Phase 1 A's existing nested schemas.
- **A3.** Verify `SchemaValidator::validateSchema()` resolves `$ref` to `mcp-tool.json` correctly when a customer saves a collection meta with `tools`. Add a Pest test that round-trips a sample definition through `CollectionSaver` → `CollectionRepository::load()` and confirms `mcp.tools` is preserved on `CollectionData->mcp`.
- **A4.** Add inline translation keys (`schema.mcp.tools_label`, `schema.mcp.tools_help`, `schema.mcp.tools_placeholder`) to all six locale files: de_DE, en_GB, en_US, es_ES, it_IT, nl_NL (both `admin.{locale}.php` and `js.{locale}.php` variants per the existing convention).

### Chunk B — Schema tool factory + filter value resolver + tool class (~3 days)

The core implementation. Largest chunk by far.

- **B1.** New `src/Domain/Mcp/Data/SavedQueryToolDefinition.php` — value object per "Component responsibilities". Constructor takes the validated JSON entry array + the parent collection name + the resolved `mcp.access` from the parent. Public readonly properties: `$name`, `$description`, `$collectionName`, `$access`, `$params`, `$filters`, `$sort`, `$limit`, `$offset`, `$include`, `$exclude`, `$format`. No behavior.
- **B2.** New `src/Domain/Mcp/Service/FilterValueResolver.php` — `resolve(string $value, array $args, array $paramsSpec): string|int|float|bool`. Single-pass placeholder substitution. Throws `SavedQueryToolException` if a `{{params.X}}` references a param not in `$paramsSpec`. Coerces substituted values to the param's declared type. Non-`params.` `{{...}}` substrings are returned verbatim (literal handling). Phpstan-level-8-clean signature.
- **B3.** New `src/Domain/Mcp/Service/SavedQueryToolFactory.php` — `build(SavedQueryToolDefinition $definition): SavedQueryTool`. Generates the dynamic-signature closure (uses `eval()` only as last resort; prefer building a closure via the existing `Closure::bind`/`fromCallable` patterns the SDK already uses). Generates the `inputSchema` JSON Schema from the param spec. Composes the auto-built description block. Returns a fully-constructed `SavedQueryTool`.
- **B3a (spike).** Half-day spike at the head of chunk B: prove the dynamic-closure-from-spec approach works against the SDK's reflection. Build a closure with named params (`function (string $city, ?float $maxPrice = null) { … }`) from a runtime spec; confirm `Mcp\Server\Builder::addTool()` accepts it and the SDK reflects on it correctly when dispatching `tools/call`. If reflection fails (e.g., SDK requires statically-defined closures), fall back to a "single `$args` array param" approach where the closure declares one `array $args` parameter and the SDK's reflection layer is told via `inputSchema` what to validate. The fallback is uglier but works; pin which approach by the end of the half-day.
- **B4.** New `src/Domain/Mcp/Tool/Schema/SavedQueryTool.php` — handler-bearing tool. Constructor takes the definition + `ObjectFetcher` + `FilterValueResolver` + `ContentRenderer` + `PersonaContext` + `ObjectUrlBuilder` + the schema-resolver for field-expose stripping. `handler(...$args): array` — the closure built by the factory delegates to this method.
- **B5.** New `src/Domain/Mcp/Exception/SavedQueryToolException.php` — domain exception. Three flavors via static factories: `forValidation()`, `forCollision()`, `forPlaceholder()`. Each carries a recovery hint string used by the tool handler when returning `isError: true`.
- **B6.** New `src/Domain/Mcp/Service/SchemaToolRegistrar.php` — `register(ToolRegistry $registry): void`. Iterates `CollectionRepository::listAllCollections()`. For each `CollectionData->mcp['tools']`, validates each entry against `mcp-tool.json`, runs collision detection, calls `SavedQueryToolFactory::build()`, registers into `ToolRegistry`. Logs collisions/validation failures to the MCP activity logger.
- **B7.** Modify `src/Domain/Mcp/Service/McpServerFactory.php` — call `SchemaToolRegistrar::register($this->toolRegistry)` once at the head of `build()`, before `forPersona()` is consumed. Idempotent: re-registers from disk on each call (sub-millisecond — collections are already in-memory).
- **B8.** Wire new services in `config/container.php` — `FilterValueResolver`, `SavedQueryToolFactory`, `SchemaToolRegistrar` are autowire-friendly singletons. `SavedQueryTool` is built per request by the factory; no DI entry.
- **B9.** Pest unit tests per service: `FilterValueResolverTest`, `SavedQueryToolFactoryTest`, `SchemaToolRegistrarTest`, `SavedQueryToolTest` — covering the full fixture matrix in the "Testing strategy" section.

### Chunk C — Admin UI: JSON textarea on the MCP tab (~1 day)

- **C1.** Modify `src/Domain/Admin/SchemaForm.php` — the existing MCP tab (Phase 1 D) gains a "Custom MCP Tools" section: a labeled `<textarea>` showing the current `mcp.tools` array as pretty-printed JSON, with a help link to the new `mcp-saved-query-tools.md` docs page.
- **C2.** Modify `resources/templates/admin/schema/edit.twig` — render the new section under the existing access/description controls. Inline JSON validation hint below the textarea ("This is JSON — check brackets and quotes if save fails").
- **C3.** Frontend save flow: `SchemaSaver` already round-trips arbitrary nested JSON in the meta; no JS change needed. Server-side validation via `SchemaValidator` catches malformed `tools` arrays and surfaces the error message inline.
- **C4.** Add `schema.mcp.tools_section_label`, `schema.mcp.tools_section_help` translation keys to all locale files. Match the locale set used in Chunk A.
- **C5.** Pest feature test: save a schema with a valid `mcp.tools` array via the admin form, confirm round-trip preserves the JSON; save with an invalid entry, confirm the error message lands in the response.

### Chunk D — Streaming verification + progress-notification worked example (~0.5 day)

- **D1.** Pest feature test: a synthetic test-only tool that calls `\Fiber::suspend([...])` via the SDK's `ClientGateway` triggers `Content-Type: text/event-stream` on the response. Confirms the SDK's auto-SSE switch works against T3's middleware stack.
- **D2.** Modify `SchemaTools::createHandler()` (Phase 1 E1 — the admin `schema_create` operation) to emit progress notifications during multi-step seeding. Two checkpoints: "schema persisted" and "seed objects created" (when called with a `jumpstart` payload). Uses `ClientGateway::sendNotification('notifications/progress', [...])` — protocol-level progress message.
- **D3.** Pest feature test: call `schema_create` via the Slim app with a seed payload, confirm the response is SSE and includes the two progress notifications followed by the final result.
- **D4.** Note: no new T3 services. The SDK does all the work. This chunk is pure verification + a worked example.

### Chunk E — Extension-starter sub-extension + T3 docs page (~1 day)

- **E1.** New `resources/docs/extensions/mcp-extensions.md` — author-facing guide. Covers:
  - The three extension hooks: `registerMcpTool()`, `registerMcpResource()`, `registerMcpResourceTemplate()`. Each with a worked example.
  - The progress-notification pattern from Chunk D, with a code sample.
  - Tool name collision policy + how to namespace tool names with a vendor prefix.
  - Common pitfalls (handler signature reflection, persona check inside the handler, returning `isError: true` not throwing).
- **E2.** New `resources/docs/extensions/mcp-saved-query-tools.md` — customer-facing guide. Covers:
  - JSON tool definition shape with annotated examples.
  - `{{params.X}}` placeholder syntax.
  - Available operators and field types.
  - Collision policy from the customer's perspective.
  - When to reach for a schema tool vs the core `query_collection` (schema tools are saved presets — use them when the same query is run repeatedly with the same shape).
- **E3.** Update `resources/docs/menu.php` — add both docs pages under "Extensions & CLI" group's MCP subgroup.
- **E4.** Rebuild docs index: `php bin/build-docs-index.php`. Commit the regenerated `resources/docs/search-index.json`.
- **E5.** Author the extension-starter sub-extension in a separate commit on the `totalcms/extension-starter` repo (NOT this PR). One custom tool (`hello_world` — takes a `name` param, returns a greeting) + one custom resource (`acme://message/of-the-day`, returns a daily message). Update the extension-starter README to point at the new docs pages. **This is the only Phase 3 deliverable that lives outside the T3 repo.**

### Chunk F — Tests + verification + docs sync (~1 day)

- **F1.** Pest cross-chunk feature tests: full `tools/list` + `tools/call` round-trip via the Slim app, schema tools included; admin persona vs public persona visibility (admin-only collection's tools invisible to public persona); collision logging on duplicate tool names.
- **F2.** End-to-end smoke with MCP Inspector: define a parameterized schema tool on the `blog` collection via the admin UI, confirm Inspector sees it in `tools/list` with the correct `inputSchema`, call it with valid + invalid args, confirm expected responses.
- **F3.** Worked-example smoke: the `find_listings` example from the docs runs against a fixture `listings` collection and returns the expected three objects when called with `{city: "Austin", max_price: 500000}`.
- **F4.** Streaming smoke: through MCP Inspector, call the `schema_create` operation with a jumpstart payload, confirm SSE stream with two `notifications/progress` messages followed by the result.
- **F5.** Run `composer run stan` (must pass Level 8). Run `composer run test` (full Pest suite).
- **F6.** Final docs index rebuild + commit.

## Configuration changes

No new top-level config keys. Phase 3 reuses Phase 0/1/2 settings:

- `mcp.enabled` — endpoint toggle (Phase 0).
- `mcp.publicAccess` — default-deny master switch (Phase 0).
- `mcp.toolPrefix` — optional prefix prepended to schema tool names same as core tool names (Phase 1 A9).
- `mcp.publicIpPerMinute` — rate limiter (Phase 1 G2).
- `mcp.subscriptionsEnabled` — subscription kill switch (Phase 2).
- `mcp.allowedOrigins` — CORS allowlist (Phase 2).

If a future need emerges to disable schema tools while keeping core tools active, add `mcp.schemaToolsEnabled` then. Not needed for v1.

## Storage shape

Schema tools live in the existing `mcp` card on each collection's `.meta.json`:

```json
{
	"id":   "listings",
	"name": "Listings",
	"url":  "/listings",
	"mcp": {
		"access":      "public",
		"description": "Real estate listings for AI agents.",
		"resource":    true,
		"tools": [
			{
				"name":        "find_listings",
				"description": "Search active listings by city and max price.",
				"params": {
					"city":      { "type": "string", "description": "...", "required": true },
					"max_price": { "type": "number", "description": "...", "minimum": 0 }
				},
				"filters": {
					"status": { "value": "active" },
					"city":   { "operator": "contains", "value": "{{params.city}}" },
					"price":  { "operator": "lte",      "value": "{{params.max_price}}" }
				},
				"sort":   "price:asc",
				"limit":  20,
				"format": "markdown"
			}
		]
	}
}
```

Validation: `mcp-collection.json` references `mcp-tool.json` via `array<$ref>` for each entry in `tools`. `SchemaValidator` already supports nested `$ref` resolution from Phase 1.

## Source layout (new files)

```
src/Domain/Mcp/
	Data/
		SavedQueryToolDefinition.php
	Exception/
		SavedQueryToolException.php
	Service/
		FilterValueResolver.php
		SavedQueryToolFactory.php
		SchemaToolRegistrar.php
	Tool/Schema/
		SavedQueryTool.php

resources/schemas/
	mcp-tool.json

resources/docs/extensions/
	mcp-extensions.md
	mcp-saved-query-tools.md

tests/
	Unit/Domain/Mcp/
		FilterValueResolverTest.php
		SavedQueryToolDefinitionTest.php
		SavedQueryToolFactoryTest.php
		SavedQueryToolTest.php
		SchemaToolRegistrarTest.php
	Feature/Mcp/
		SchemaToolsTest.php
		StreamingTest.php
	fixtures/mcp/
		listings.meta.json
		listings-objects/   (small set of fixture objects)
```

## Source layout (modifications)

- `resources/schemas/mcp-collection.json` — add `tools: array<mcp-tool>`.
- `src/Domain/Mcp/Service/McpServerFactory.php` — call `SchemaToolRegistrar::register()` at head of `build()`.
- `src/Domain/Admin/SchemaForm.php` — add "Custom MCP Tools" section to MCP tab.
- `resources/templates/admin/schema/edit.twig` — render the new textarea.
- `src/Domain/Mcp/Tool/Admin/SchemaTools.php` — `createHandler()` emits progress notifications during seeding (Chunk D).
- `config/container.php` — wire `FilterValueResolver`, `SavedQueryToolFactory`, `SchemaToolRegistrar`.
- `resources/docs/menu.php` — add new docs pages.
- `resources/docs/search-index.json` — regenerated.
- `resources/translations/{admin,js}.{de_DE,en_GB,en_US,es_ES,it_IT,nl_NL}.php` — new keys per Chunk A4 + C4.

## Existing T3 infrastructure to reuse

- **`ToolRegistry`** (`src/Domain/Mcp/Service/ToolRegistry.php`) — same persona-filtered store core tools live in. Schema tools register through it.
- **`ContentRenderer`** (Phase 1 F) — `markdown|html|text` formatting routed through here.
- **`QueryPipeline`** (`src/Domain/Query/Service/QueryPipeline.php`) — REST `include`/`exclude` filter syntax. Schema tools generate include/exclude strings from their structured `filters` block and call this directly.
- **`ObjectFetcher`** — object loading + persona-aware safety filter application (Phase 1).
- **`ObjectUrlBuilder`** — `buildUrl()` to decorate results.
- **`PersonaContext`** (Phase 0) — request-scoped persona store; schema tool handlers read this to enforce access.
- **`McpDescriptionResolver`** + **`McpSchemaResolver`** (Phase 1 A6/A7) — field-expose stripping, type-inferred filterable/sortable.
- **`SchemaValidator`** — JSON Schema validation of `mcp.tools` arrays against `mcp-tool.json`.
- **`McpSessionInvalidator`** (Phase 1 G5) — already wired to `schema.saved` event, so saving a schema with `mcp.tools` changes naturally clears active sessions.
- **`ClientGateway`** (SDK) — `sendNotification()` for the Chunk D progress-notification worked example.
- **MCP activity logger** (Phase 1 G3) — schema tool calls log through this same channel.
- **`mcp.tool.called` event** (Phase 1 H4) — fires for schema tools same as core tools.

## Key risks

1. **Dynamic-closure-from-spec approach** (Chunk B3a addresses). The SDK reflects on tool handler closures by name. If we build a closure dynamically from a JSON `params` spec, the SDK needs to accept it. The half-day spike at the head of Chunk B confirms feasibility; the fallback (one `$args` array parameter, SDK-side validation via `inputSchema`) is uglier but works. **Risk: low — the spike de-risks it; if it fails, scope adjusts to the array-arg fallback with no schedule impact, just slightly less idiomatic handlers.**
2. **JSON textarea UX is power-user-only.** A customer who's never edited JSON will struggle. **Mitigation: docs page (Chunk E2) has copy-pasteable templates; the structured form-builder repeater UX is on the 3.5.x backlog if customer feedback warrants.**
3. **Filter operator coverage.** `QueryPipeline`'s `include/exclude` REST syntax doesn't expose every operator we list in `mcp-tool.json` (`starts`, `ends`, `notin` in particular). **Mitigation: Chunk B6's pre-flight against `QueryPipeline` enumerates the actually-supported operator set; `mcp-tool.json` schema is trimmed to match. Any operator not in the supported set is rejected at validation time with a clear message.**
4. **Tool-name collisions across collections** are detected at server-build time, not at admin save time. A customer can save two schemas with colliding tool names, then discover the collision the next time `/mcp` is hit. **Mitigation: Chunk C5's save-time hook also checks for collisions against the currently-registered tool set and warns inline; the runtime check is a belt-and-suspenders backstop.**
5. **`{{params.X}}` placeholder syntax collision with future use of `{{...}}` for other purposes** (e.g., date macros like `{{this_month_start}}`). **Mitigation: validator warns on any unrecognized `{{...}}` literal so customers know it's not being substituted. Future macros adopt a clearly different prefix (e.g., `{{macro:this_month_start}}`).**
6. **Progress-notification SSE end-to-end fidelity.** The SDK's transport-level auto-SSE switch is well-understood (Phase 0 verified the basic SSE path), but emitting `notifications/progress` through it in mid-tool-call hasn't been exercised. **Mitigation: Chunk D1's targeted Pest test exercises the path before Chunk D2 modifies any production tool.**

## Verification

### Phase 3 acceptance criteria

- Pest unit + feature tests pass for all new services (`FilterValueResolver`, `SavedQueryToolFactory`, `SchemaToolRegistrar`, `SavedQueryTool`).
- Saving a collection meta with `mcp.tools` via the admin UI surfaces the tool in `tools/list` on the next `/mcp` hit (no restart).
- A parameterized schema tool dispatches end-to-end: caller args validated against `inputSchema`, filter placeholders substituted, persona check enforced, draft filter applied for public persona, fields with `expose: false` stripped from results, URLs decorated, response shape matches the existing core content-tool envelope.
- A schema tool name collision (tool-vs-core, tool-vs-extension, tool-vs-tool) is logged and the offending tool is absent from `tools/list`; the endpoint still boots.
- `schema_create` with a seed payload streams SSE with two `notifications/progress` events followed by the result.
- `resources/docs/extensions/mcp-extensions.md` and `mcp-saved-query-tools.md` live; docs index regenerated.
- `composer run stan` passes Level 8.
- `composer run test` passes.

### Out-of-PR verification (separate commits)

- Sub-extension in `totalcms/extension-starter` registers a custom tool + resource using the documented hooks; confirmed working against a local T3 install.
- Docs site (`docs.totalcms.co`) sync per CLAUDE.md after PR merges to main.

## Anthropic Directory readiness items added in Phase 3

Phase 1 + 2 covered the bulk of directory-submission readiness. Phase 3 adds:

- **Tool count grows** — schema tools count toward the per-server tool budget. Document the operator-side guidance: keep total tool count below ~50 (Claude Desktop UX degrades above that). Add to the `mcp-saved-query-tools.md` docs page.
- **Tool name length cap** — schema tools enforce ≤ 64 chars *including* the optional `mcp.toolPrefix`. Validator catches violations at save time with a clear error message.
- **No tool-description prompt injection** — schema tools' descriptions are customer-authored. Docs page calls out the "don't write 'always call X' or 'you must use this' in descriptions" guidance; runtime validator does NOT enforce (false-positive risk too high), but the docs make the rule explicit.

## Out of scope (deferred)

- **Structured form-builder UI for schema tools** (repeater fields per tool). Deferred to 3.5.x if customer feedback shows the JSON textarea is too power-user-only.
- **Cross-collection schema tools** (a tool that queries multiple collections and stitches results). Deferred indefinitely — if anyone needs this, they write a PHP extension via `registerMcpTool()`.
- **Nested object / array param types**. `params` types are restricted to `string|number|integer|boolean` in v1. Adding nested object support is additive but non-trivial; defer until a real use case appears.
- **Date / macro placeholders** (`{{macro:this_month_start}}` etc.). Useful, but designing the macro vocabulary needs its own brainstorm. The `{{params.X}}` syntax leaves room for a future macro syntax that doesn't conflict.
- **Schema-tool aggregations** (`count`, `sum`, `group_by`). Aggregations are a different beast — they want `DataView`-style infrastructure, not the saved-query-template surface. Out of scope.
- **Per-tool rate limits**. Tools inherit the endpoint's IP rate limit (Phase 1 G2). Per-tool limits land with OAuth scopes in Phase 4.
- **Output schemas on schema tools**. Phase 2 added `outputSchema` to core content tools; schema tools could too, but the dynamic-schema-from-collection-fields work is non-trivial and not pass/fail at directory review. Defer to 3.5.x polish.

## Effort summary

| Chunk | Effort | Description |
|---|---|---|
| A. mcp-tool.json + mcp-collection.json extension | ~0.5 day | Schema files + validation wiring + translations |
| B. Factory + resolver + tool class + registrar | ~3 days | Core implementation; includes half-day spike on dynamic-closure-from-spec |
| C. Admin UI: JSON textarea | ~1 day | SchemaForm + Twig + save-time collision warning + translations |
| D. Streaming verification + worked example | ~0.5 day | Pest tests + retrofit `SchemaCreateTool` with progress notifications |
| E. Extension docs + starter sub-extension | ~1 day | Two T3 docs pages + index rebuild; extension-starter repo update (separate commit) |
| F. Cross-chunk tests + E2E smoke + final docs sync | ~1 day | Full Pest run, MCP Inspector smoke, PHPStan Level 8 |
| **Total Phase 3** | **~7 days (~1.5 weeks)** | **Schema-defined custom tools + streaming pattern + extension docs** |

Earlier estimate quoted ~8.5 days; Chunk B's half-day spike absorbs some of what I'd separately budgeted as buffer, and Chunks D + F came in tighter on detail than expected.
