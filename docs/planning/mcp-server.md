# T3 MCP Server — Feature Plan

**Status:** Planning (2026-05-01) — headline feature for 3.3 platform release
**Supersedes:** `docs/planning/7-brief-mcp.md` (kept for historical context — the brief was scoped narrowly to developer scaffolding; this plan adds the consumer-facing content surface)
**Related:** Docs MCP server (`docs/planning/1-brief-docs-mcp.md` — separate project, serves T3 documentation), CLI (shipped 3.3), Extensions (shipped 3.3), Internationalization plan (`docs/planning/internationalization.md` — 3.4)

## Goal

Turn every T3 instance into its own MCP server. The same `/mcp` endpoint serves three different audiences from one codebase, with tool visibility scoped by authentication:

- **Developers building T3 sites** get scaffolding tools — schema management, template editing, content seeding — so an AI agent in their editor can drive a real T3 install without terminal access.
- **Site owners exposing their content to AI** get auto-generated, schema-aware tools for every public collection — `query_products`, `search_blog`, `get_case_study` — so external AI agents can discover and consume their content.
- **Third-party AI consumers** can connect to a customer's T3 site via OAuth and pull live data ("connect Joe's Bistro to your assistant") with the same protocol.

This is a market positioning claim, not just a feature: **every T3 site is an MCP server out of the box.** That positioning only works if MCP ships in core, with the endpoint live the moment a site is installed. Extensions add custom tools on top — they don't gate the baseline capability.

## Non-goals

- Built-in vector embedding generation. Semantic search ships with an extension hook so customers can plug in Pinecone, Qdrant, OpenAI embeddings, or local models — T3 doesn't pick a vendor.
- Marketplace for shareable tool packs. Restaurant pack, real estate pack, photography pack — interesting future work, not v1.
- AI-assisted tool generation (Claude generates custom tools from schemas). Cool but speculative.
- MCP prompts. Templated workflows are real MCP feature, but skip for v1.
- Self-modifying schemas via consumer-facing tools. Schema writes are admin-only, full stop.
- Replacing the REST API. MCP is a parallel surface optimized for AI consumption; REST stays for traditional clients.

## Architecture

### The Three Personas

The most important conceptual model in the plan. One endpoint, three audiences, tool visibility scoped by authentication:

| Persona | Auth | Tools Exposed |
|---|---|---|
| **Site owner / developer** | Admin API key | Everything: schema management, template editing, full content CRUD, plus all consumer-facing tools so they can dogfood |
| **Anonymous public consumer** | None | Auto-generated read tools for collections marked `mcpAccess: "public"`; site-wide search; resources for public content |
| **Authenticated third-party consumer** | OAuth or scoped token | Public tools plus any tools/collections explicitly granted in token scope |

Per-collection access level is a schema setting. Per-tool granular permissions can be configured for OAuth grants.

A developer using Claude Code to build a site is on the admin API key — they see the full surface AND can verify "if I were a public AI agent right now, what would I see?" by introspecting the public tool list. Same connection, dual perspective.

### Endpoint and Protocol

- Single endpoint: `POST /mcp` (HTTP) and `GET /mcp/sse` (Server-Sent Events for streaming).
- MCP protocol version target: **2025-06-18** (latest spec at time of planning). Endpoint includes a protocol version negotiation header so newer/older clients can be served.
- Discovery: `GET /.well-known/mcp.json` returns server metadata, available auth methods, public tool list, and protocol version.
- Standard MCP message types: `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `resources/subscribe`.

### Core, with Extension Hooks

The MCP endpoint, protocol handler, auth layer, auto-generated content tools, admin/dev tools, discovery, and observability all ship in **T3 core**. The extension system layers on top:

- Extensions can register custom tools via `ExtensionContext::registerMcpTool()` (e.g., Site Builder ships template tools as a Site-Builder-installed addition)
- Extensions can register custom resources via `ExtensionContext::registerMcpResource()`
- Extensions can provide semantic search backends via `ExtensionContext::registerMcpSearchProvider()` (Phase 5)

This matches how Twig works: core ships the engine, extensions add functions. Customers don't have to install anything to get an MCP server; they install extensions to get *more* MCP capabilities.

### Source Layout

```
src/
    Action/
        Mcp/
            McpEndpointAction.php          # Main /mcp handler
            McpDiscoveryAction.php         # /.well-known/mcp.json
            McpSseAction.php               # SSE streaming endpoint
    Domain/
        Mcp/
            McpServer.php                  # Protocol orchestration
            McpProtocol.php                # Message parsing/serialization
            ToolRegistry.php               # Built-in + extension-registered tools
            ResourceRegistry.php           # Built-in + extension-registered resources
            ToolGenerator.php              # Auto-generates tools from schemas
            ToolDescriptor.php             # Builds descriptions from schema field docs
            McpAuth.php                    # Persona resolution from auth headers
            ScopeResolver.php              # Per-token tool/collection scoping
            ContentRenderer.php            # Markdown/HTML/text rendering
            McpLogger.php                  # Per-token observability
            Tools/
                Admin/
                    SchemaTools.php
                    TemplateTools.php
                    SiteTools.php
                    CacheTools.php
                Content/
                    QueryTool.php          # Auto-generated per-collection
                    GetTool.php            # Auto-generated per-collection
                    SearchTool.php         # Site-wide and per-collection
        Mcp/Auth/
            ApiKeyAuth.php
            PublicAuth.php
            OAuthProvider.php              # Phase 4
            ScopedTokenManager.php
```

### Tool Surface

#### Admin / Dev Tools (preserved from the original brief)

Available only to admin API key holders:

```
schema_list()
schema_get(name)
schema_create(definition)
schema_update(name, definition)
schema_delete(name)

template_list()                            # When Site Builder installed
template_get(path)
template_save(path, content)
template_delete(path)

site_info()                                # T3 version, edition, PHP, locales, extensions
cache_clear()
extension_list()
collection_admin_list()                    # Admin-side collection metadata, raw
collection_admin_create(collection, data)  # Admin write — bypasses public access scoping
```

#### Auto-Generated Content Tools (the consumer surface)

For every collection with `mcpAccess` set to `public` or `authenticated`, T3 auto-generates a tool family:

```
query_<collection>(filters, sort, limit, page, locale?, format?)
  → Returns matching objects. Filters strongly typed from schema fields.

get_<collection>(id, locale?, format?)
  → Returns single object by ID.

search_<collection>(query, limit, locale?)
  → Text search within the collection. Backed by indexed search; semantic backend in Phase 5.
```

Tool names omit the `tcms_` prefix — AI agents don't care that it's T3 underneath. `query_products`, not `tcms_query_products`.

Filter parameters are typed from schema fields:
- A `text` field → exact match and `*_contains` filters
- A `number` field → `min_*` / `max_*` filters, sortable
- A `date` field → `*_before` / `*_after`, sortable
- A `toggle` field → boolean filter
- A `select` field → enum-typed filter with the schema's options as choices

Tool descriptions are generated from:
- Collection: schema's `mcpDescription` or fallback to general description
- Filter parameters: each field's `description` in the schema
- Auto-included: "Returns matching objects with the following fields: [field_list]"

This means schema field documentation directly drives AI usability. A schema with good field docs produces a tool AI agents can use confidently; a schema with no docs produces a tool AI agents will misuse. Strong incentive to document schemas.

#### Site-Wide Tools

```
search(query, collections?, limit, locale?)
  → Cross-collection search. Returns mixed-type results with collection name on each.

get_resource(uri)
  → Fetches any MCP resource by URI (alternative to collection-specific get tools).

list_collections()
  → Returns publicly-discoverable collections with descriptions and tool names.
```

#### Custom Tools (Extension API)

Two ways to register custom tools:

**PHP-defined via extension** (full programmatic control):

```php
$context->registerMcpTool(
    name: 'check_availability',
    description: 'Check restaurant availability for a date and party size.',
    parameters: [
        'date' => ['type' => 'string', 'format' => 'date', 'required' => true],
        'party_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'required' => true],
    ],
    access: 'public',
    handler: AvailabilityChecker::class
);
```

**Schema-defined via JSON** (no PHP, for simple data-bound tools):

Site config can declare tools that are essentially saved query templates:

```json
{
    "tools": [
        {
            "name": "find_listings_in_city",
            "description": "Find real estate listings in a specific city, optionally filtered by price.",
            "collection": "listings",
            "access": "public",
            "filters": {
                "city": { "type": "string", "required": true },
                "max_price": { "type": "number", "field": "price", "operator": "lte" }
            }
        }
    ]
}
```

Extensions can also override or supplement auto-generated tools (e.g., add a `nearby_listings(lat, lng, radius)` tool that the auto-generator can't produce).

### Resources

Resources are MCP's first-class "addressable content" concept — distinct from tools, designed for AI to reference and fetch by URI.

URI scheme: `tcms://{collection}/{id}` for objects, `tcms://{collection}/` for the collection itself.

For every collection with `mcpResource: true` (defaults true for `public` and `authenticated` access), T3 exposes:

- `tcms://blog/` — the blog collection as a resource list
- `tcms://blog/my-post-2026` — individual post as a fetchable resource
- `tcms://products/sku-12345` — individual product

When i18n lands (3.4), URIs become locale-prefixed: `tcms://en/blog/my-post`. Until then, the default locale is implicit.

Resources are how AI agents bookmark, reference, and re-fetch content across conversations. Tools are imperative; resources are declarative. A content site needs both — the brief only had tools.

### Discovery

`GET /.well-known/mcp.json`:

```json
{
    "mcpVersion": "2025-06-18",
    "endpoint": "https://example.com/mcp",
    "sseEndpoint": "https://example.com/mcp/sse",
    "name": "Joe's Photography",
    "description": "Wedding and portrait photography portfolio",
    "auth": {
        "public": true,
        "apiKey": { "header": "X-API-Key" },
        "oauth": {
            "authorizationUrl": "https://example.com/oauth/authorize",
            "tokenUrl": "https://example.com/oauth/token"
        }
    },
    "publicTools": ["query_portfolio", "get_portfolio", "search_portfolio", "search"],
    "publicResources": ["tcms://portfolio/", "tcms://about/"],
    "locales": ["en-US", "de"]
}
```

This is the AI agent's onboarding surface — a single fetch tells the agent what's available without any auth. Agents can then decide whether to connect, which auth method to use, and what tools to load.

### Auth and Permission Model

Three auth methods, picked per-request:

**Admin API key** (existing T3 API key system):
- `X-API-Key` header
- Full tool surface, all collections, both read and write
- Same keys as REST API; managed in existing admin UI

**Public access** (no auth):
- Available only when `mcpAccess: "public"` on a collection or tool
- Read-only
- Default deny — collections must explicitly opt in
- Rate-limited per IP

**OAuth / scoped tokens** (Phase 4):
- OAuth 2.1 with PKCE flow
- Customer approves "this AI agent wants access to: blog (read), contact_form (submit)"
- Per-token scope: which collections, which tools, which operations
- Per-token rate limits and observability
- Customer can revoke tokens from admin

Per-collection schema setting:

```json
{
    "name": "products",
    "mcp": {
        "access": "public",
        "description": "Catalog of available products with pricing and stock status.",
        "resource": true,
        "fields": {
            "internal_notes": { "expose": false },
            "supplier_cost": { "expose": false },
            "price": { "filterable": true, "sortable": true }
        }
    }
}
```

Schema editor in admin gets an "MCP" tab where the customer toggles access, edits descriptions, picks exposed fields, configures filters. No PHP required to make a collection AI-ready.

### Content Rendering

Tools that return content (full blog post body, product description) accept a `format` parameter:

- `markdown` (default) — sanitized markdown, token-efficient, semantic
- `html` — rendered HTML, for clients that need it
- `text` — plain text, for clients that want zero formatting

Markdown is the default because it's the highest-quality input for LLM reasoning per token. HTML wastes tokens on tags AI doesn't care about. T3 already has markdown rendering for `styledtext` fields via Tiptap — we reuse that pipeline.

### i18n Integration (Forward-Compatible from Day One)

i18n ships in 3.4, after MCP in 3.3. To avoid a painful retrofit, MCP is designed locale-aware from the start even though i18n isn't there yet:

- All auto-generated tools accept an optional `locale` parameter
- Resource URIs reserve a locale segment: `tcms://blog/post-1` works now; `tcms://en-US/blog/post-1` will work post-i18n
- Discovery JSON includes a `locales` field
- Tool description generator is aware of locale-aware fields and includes "(locale-aware)" in the description

When i18n lands, the locale parameter starts doing real work and the URI form becomes canonical. No breaking changes for clients that ignored the locale parameter.

### Observability

Per-token logging from Phase 1, customer-visible dashboard from Phase 4:

- Every tool call logged with: tool name, parameters, persona/token, response time, result count, locale, timestamp
- Per-token quotas and rate limits
- Anomaly detection: sudden spike in calls, unusual parameter patterns, repeated 401s
- Admin dashboard "MCP Activity" tile (Phase 4): top consumers, popular tools, recent errors, tokens to review
- Webhook events for: token created, token revoked, rate limit hit, anomaly detected — wired to existing event system

This is critical for customer trust. Opening your content to AI is scary; visible "here's exactly what AI did with my site" makes it tractable.

### CORS

Per-token CORS configuration — admin sets allowed origins when minting a token. Public access has a configurable global origin allowlist. Default deny for browser-based AI clients; opt in by origin.

### Streaming and Long Responses

`/mcp/sse` SSE endpoint for streaming responses. Triggered automatically for:

- Long content (full article bodies > 4KB)
- Multi-page query results when AI requests `stream: true`
- Custom tools that explicitly opt into streaming

MCP protocol supports streaming natively; T3 wires it to Slim's response stream.

### Rate Limiting

Layered:

- Per-IP for public access (inherits T3's existing IP rate limiter)
- Per-token for authenticated access (configurable per token, defaults from extension settings)
- Per-tool ceiling for expensive tools (configurable in custom tool registration)
- Global emergency switch in extension settings ("disable MCP entirely")

### Protocol Versioning

MCP spec is evolving. Strategy:

- Server declares supported protocol versions on `initialize` handshake
- Multiple protocol versions can be supported simultaneously via adapters in the protocol layer
- Migration window: when a new protocol version lands, T3 supports old + new for at least one minor release before deprecating old
- Discovery JSON declares protocol version explicitly

## Phases

### Phase 1 — Core MCP Server (3.3 ship)

**Effort: ~3–4 weeks**

This is the headline of 3.3. Must ship enough that "every T3 site is an MCP server" is true, with both developer and consumer surfaces working.

- `POST /mcp` endpoint with MCP protocol 2025-06-18 compliance
- `GET /.well-known/mcp.json` discovery
- Admin API key auth (reuses existing T3 API keys)
- Public auth path (no auth required for `mcpAccess: "public"` collections)
- Admin/dev tools: schema_*, template_* (when Site Builder installed), site_info, cache_clear
- Auto-generated content tools per collection: `query_<collection>`, `get_<collection>`, `search_<collection>` with schema-typed filters
- Site-wide tools: `search`, `list_collections`, `get_resource`
- Per-collection MCP config in schemas (`access`, `description`, `resource`, `fields`)
- Schema editor "MCP" tab in admin
- Markdown content rendering by default
- Locale parameter on all tools (forward-compat for i18n)
- Per-token logging to event system
- IP-based rate limiting (inherited)

**Done:** install T3, fresh site has `/mcp` and `/.well-known/mcp.json` live. Admin can mark a collection as public, configure descriptions, and within a minute an external AI agent can discover the site and query the collection. Developer with Claude Code + admin API key can scaffold schemas, write templates, and seed content from a single editor session.

### Phase 2 — Resources and Public Surface Polish

**Effort: ~2 weeks**

- MCP resources implementation (`tcms://` URI scheme, `resources/list`, `resources/read`, `resources/subscribe`)
- Per-collection resource configuration
- Resource subscriptions (notify when content changes — uses existing event system)
- Improved auto-tool descriptions (sourced from field-level documentation)
- CORS per-token + global allowlist for public

**Done:** AI agents can bookmark site content as resources and subscribe to updates. Browser-based AI clients can connect with proper CORS handling.

### Phase 3 — Custom Tools and SSE Streaming

**Effort: ~2 weeks**

- `ExtensionContext::registerMcpTool()` and `registerMcpResource()` hooks
- Schema-defined custom tools (saved query templates without PHP)
- `/mcp/sse` SSE endpoint with streaming response support
- Long-content streaming for large bodies and result sets
- Custom tool examples in extension starter

**Done:** extensions can ship MCP tools alongside Twig functions and CLI commands. Customers without PHP skills can define data-bound tools in schema config. Long content streams cleanly.

### Phase 4 — OAuth, Scoped Tokens, Observability Dashboard

**Effort: ~2–3 weeks**

- OAuth 2.1 with PKCE for third-party AI consumers
- Scoped tokens (per-tool, per-collection, per-operation, per-rate-limit)
- "Connect this AI to my site" consent flow
- Customer-visible observability dashboard: top consumers, popular tools, recent errors, anomalies
- Per-token rate limits and quotas
- Webhook events for security signals
- Token revocation UI

**Done:** customer can mint a scoped token for a specific AI agent ("Cursor for this client engagement") with limited tool access and visible activity log. OAuth flow lets third-party services connect to the customer's site safely.

### Phase 5 — Semantic Search and Advanced

**Effort: ~2–3 weeks**

- `ExtensionContext::registerMcpSearchProvider()` hook for semantic backends
- First-party Pinecone / Qdrant / OpenAI embedding extensions (separate repos)
- Search tools fall back to text search when no semantic provider is registered
- MCP "prompts" feature (templated workflows shipped with collections)
- Performance optimization based on Phase 1–4 observability data

**Done:** customers can plug in a semantic search backend of their choice. AI agents get "find articles about hooks" working even when the article doesn't say "hooks" verbatim.

## Effort Summary

| Phase | Effort | Cumulative | Target |
|---|---|---|---|
| 1. Core MCP server | 3–4 weeks | 4 weeks | **3.3 ship** |
| 2. Resources + polish | 2 weeks | 6 weeks | 3.3.x or 3.4 |
| 3. Custom tools + SSE | 2 weeks | 8 weeks | 3.4 |
| 4. OAuth + observability | 2–3 weeks | 11 weeks | 3.4 or 3.5 |
| 5. Semantic search + advanced | 2–3 weeks | 14 weeks | 3.5+ |

**Total: ~11–14 weeks** for all phases. Phase 1 alone (~4 weeks) is the 3.3 headline and delivers the marketable claim.

## T3-Side Changes Summary

- **Routing:** `/mcp`, `/mcp/sse`, `/.well-known/mcp.json`
- **Schema:** new `mcp` block per collection (`access`, `description`, `resource`, `fields`); `tools` block per site for schema-defined custom tools
- **Admin:** MCP tab on schema editor; MCP token UI (Phase 4); MCP activity dashboard tile (Phase 4)
- **API Keys:** existing system extended with MCP scope flags
- **Services:** `McpServer`, `ToolRegistry`, `ResourceRegistry`, `ToolGenerator`, `McpAuth`, `McpLogger`, `ContentRenderer`, `OAuthProvider` (Phase 4), `ScopedTokenManager` (Phase 4)
- **Extensions API:** `registerMcpTool()`, `registerMcpResource()`, `registerMcpSearchProvider()` (Phase 5) on `ExtensionContext`
- **CLI:** `tcms mcp:status`, `tcms mcp:tokens`, `tcms mcp:test <tool>` for local testing
- **Events:** `mcp.tool.called`, `mcp.token.created`, `mcp.token.revoked`, `mcp.rate_limit.hit`, `mcp.anomaly.detected`
- **Config:** `mcp.enabled`, `mcp.publicAccess`, `mcp.rateLimits`, `mcp.cors.allowedOrigins`

## Combined Workflow Examples

### Developer Workflow (admin API key)

**Developer:** *"Set up a portfolio site with a case studies collection. Fields: title, client name, year, cover image, body text. Then write a Twig template that loops through them."*

Agent calls:
1. `docs_search("custom schema Pro edition")` → confirms field types available (docs MCP)
2. `site_info()` → confirms T3 version and edition (T3 MCP)
3. `schema_create({...})` → creates schema on the live instance
4. `docs_twig_function("cms.objects")` → gets exact loop syntax (docs MCP)
5. `template_save("pages/portfolio.twig", content)` → saves the template

Developer gets a working portfolio collection and template without opening the admin or writing Twig manually.

### Consumer Workflow (public access)

A potential client's AI assistant:

1. `GET https://photographer-site.com/.well-known/mcp.json` → discovers the site, sees `query_portfolio` and `search` are public
2. `query_portfolio({ year: 2025, theme: "wedding" })` → returns matching photos with descriptions and URLs
3. `get_portfolio("sunset-wedding-2025")` → returns full case study including markdown body

Client says "show me wedding photos with sunset themes from 2025" — assistant answers from the live site without any setup.

### Third-Party Connection (OAuth, Phase 4)

Restaurant owner clicks "Connect Joe's Bistro to Claude" in Claude Desktop. OAuth flow:

1. Owner is redirected to `joesbistro.com/oauth/authorize`
2. Approves: read menu, read hours, accept reservation requests
3. Returns to Claude with a scoped token
4. Claude can now answer "What are the vegan options at Joe's Bistro?" and "Book me a table for 4 on Friday at 7pm" using `query_menu` and `submit_reservation` tools

## Security Considerations

- **Default deny for public access.** Collections are admin-only until explicitly opted in.
- **Field-level exposure control.** `internal_notes`, `supplier_cost`, etc. can be marked `expose: false` and never appear in MCP responses.
- **Schema writes are admin-only, always.** No consumer-facing path to modify schemas.
- **OAuth tokens are user-scoped.** Customer mints them, reviews them, revokes them.
- **Rate limiting is layered.** IP, token, per-tool, global emergency switch.
- **Anomaly detection alerts the customer.** Webhook on suspicious patterns ("contact_form tool called 500 times in 10 minutes from one token").
- **No raw filesystem access.** Tools route through service layer, same auth/permission checks as REST API.
- **CORS default deny.** Browser-based AI must be explicitly authorized.
- **Audit log retention.** All tool calls logged for at least 30 days.
- **Disabling the feature.** `mcp.enabled: false` in config kills the endpoint entirely.

## Open Questions

- **Auto-tool generation strategy when schemas change.** When a customer adds a field, does the auto-generated tool refresh on next call (live introspection) or require a regenerate command (build-time generation)? Probably live introspection with caching, but worth thinking about cache invalidation.
- **Tool naming collisions.** Customer has collections `products` and `product_reviews`. Auto-generates `query_products` and `query_product_reviews` — fine. But what if an extension also registers `query_products`? Strict deny? Last-write-wins? Auto-prefix? Lean toward strict deny with admin warning.
- **Markdown-from-HTML conversion fidelity.** Tiptap → markdown round-trips well; arbitrary HTML embedded via raw blocks may not. Need a fallback strategy.
- **OAuth provider scope.** Build OAuth from scratch (more work, full control) vs use a library like `league/oauth2-server` (faster, well-tested, may not fit MCP use cases perfectly). Lean toward library.
- **Resource subscription cost.** `resources/subscribe` keeps a connection open. How many concurrent subscriptions per token? Per site? Need a sensible default and clear configuration.
- **Schema-defined custom tools — how powerful?** A simple `WHERE` filter builder is easy. Aggregations, joins, and transformations get complex fast. For v1, scope to filter+sort+limit on a single collection. Anything more requires PHP.
- **Discovery for non-public sites.** Should `/.well-known/mcp.json` exist on sites with no public access (just admin)? Probably yes (it tells AI agents "auth required, here's how"), but the public tool list will be empty.
- **Versioning strategy when MCP spec breaks compatibility.** Currently planning to support multiple protocol versions in parallel; need to think about how long old versions stay around and how customers are notified of deprecations.
- **Telemetry vs privacy.** Per-token logs are stored on the customer's server. Do we offer an aggregated "fleet metrics" rollup back to T3 for product insights? Probably opt-in only, anonymized, off by default.
- **Public access and crawler abuse.** AI agents can hammer public read tools as a free scraping API. Rate limiting helps, but worth thinking about explicit "this is for AI agents, not crawlers" signaling and abuse response.

## What Done Looks Like

- A fresh T3 install has `/mcp` and `/.well-known/mcp.json` live without any extension installed.
- An admin can mark a collection as public via the schema editor's "MCP" tab; within a minute, an external AI agent can discover the site and query the collection.
- A developer with Claude Code + admin API key can scaffold schemas, write templates, and seed content from a single editor session — same workflow the original brief promised.
- A photography portfolio site exposes `query_portfolio`, `get_portfolio`, `search_portfolio` as auto-generated tools with descriptions sourced from schema field documentation.
- A restaurant can ship a custom `check_availability` tool via extension or schema config.
- AI agents can fetch content as MCP resources (`tcms://blog/my-post`) and subscribe to updates.
- Long content streams over SSE rather than blocking.
- Browser-based AI clients can connect with proper CORS configuration.
- A customer can mint a scoped OAuth token for a specific third-party AI agent and revoke it later. The customer-visible activity dashboard shows what the AI has been doing.
- Anomalous usage patterns trigger webhook alerts.
- Rate limits are enforced per-IP for public, per-token for authenticated, per-tool for expensive operations, with a global kill switch.
- Schema field documentation directly drives MCP tool descriptions, creating a strong incentive to document schemas well.
- Disabling MCP entirely (`mcp.enabled: false`) returns the site to non-MCP behavior with no other effects.
- When i18n ships in 3.4, the existing locale parameter on tools and the reserved locale segment in resource URIs start doing real work — no breaking changes for existing clients.
- T3's market positioning becomes "the flat-file CMS where every site is an MCP server out of the box." Marketable, defensible, and true.
