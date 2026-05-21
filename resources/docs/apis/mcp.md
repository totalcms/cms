---
title: "MCP Server"
description: "Expose your Total CMS site as a Model Context Protocol server so AI agents (Claude, ChatGPT, custom integrations) can discover, query, and manage content."
related:
  - apis/rest-api
  - apis/api-keys
  - schemas/reference
  - extensions/extension-points
  - operations/security
audience: intermediate
updated: 2026-05-21
---

# MCP Server

Every Total CMS site is an MCP server out of the box. Point Claude Code, Claude Desktop, ChatGPT, or any conformant MCP client at `https://your-site/mcp` and an AI agent can query your collections, fetch objects, search content, and (with an API key) manage schemas and collections.

The MCP server is **Pro+ edition only** and shipped with Total CMS 3.5.

---

## What is MCP?

The Model Context Protocol is Anthropic's open standard for how AI agents talk to tools and data sources. An MCP server publishes a set of **tools** (callable functions with typed inputs/outputs) and **resources** (addressable content URIs); the AI host (Claude, etc.) lets the agent invoke them.

For Total CMS specifically, MCP gives agents:

- **Discovery** — `list_collections` and `describe_collection` map the site's content shape.
- **Read** — `query_collection`, `search_collection`, `get_object` fetch content with the same filter/sort syntax as the REST API.
- **Write (admin)** — `create_schema`, `update_schema`, `delete_schema`, `create_collection`, `clear_cache`, `list_extensions`, `get_site_info` for operator-driven workflows from inside the agent.

---

## Three audiences, one endpoint

The same `/mcp` URL serves three personas; the tool surface scales per caller:

| Persona | How they authenticate | What they see |
|---|---|---|
| **Developer / operator** | `X-API-Key: <admin-key>` header on every request | Every tool — including the admin write tools. Same surface as the admin UI. |
| **Public AI agent** | No credentials (anonymous) | Only tools marked `access: public` AND only collections with `mcp.access: 'public'`. Drafts are auto-hidden. |
| **OAuth-scoped consumer** | Phase 4 (not in 3.5) | Reserved for a future "connect Joe's Bistro to Claude" flow. |

Public access is **default-deny**. Anonymous requests get a 401 unless the operator explicitly flips `mcp.publicAccess` on in settings AND marks at least one collection's `mcp.access` as `public` in the schema editor.

---

## Enabling the MCP server

1. **Check your edition.** MCP requires Pro or higher. Trial counts as Pro for testing.
2. **Verify it's enabled.** In **Admin → Settings → MCP Server**, `Enabled` should be checked (default true on fresh install).
3. **Confirm with the CLI:**
   ```bash
   tcms mcp:status
   ```
   Look for `enabled: yes`, `edition gate: yes`, and a non-empty `Admin persona` tool list.
4. **Test through the endpoint.** From a terminal:
   ```bash
   curl -X POST https://your-site/mcp \
     -H 'Content-Type: application/json' \
     -H 'Accept: application/json, text/event-stream' \
     -H 'X-API-Key: <your-admin-key>' \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"manual","version":"1"}}}'
   ```
   A successful response carries `result.serverInfo` and `result.capabilities`.

For Claude Desktop / Claude Code: add your site under MCP servers in the client's settings — point at the `/mcp` URL and provide the API key as the Bearer token if the host supports it, otherwise as a header config.

---

## Tool catalog

All tool descriptions are also visible to the AI client at runtime via `tools/list`. The catalog below is the canonical reference.

### Discovery

| Tool | Access | What it does |
|---|---|---|
| `list_collections` | public | Persona-filtered overview of every collection. Returns id, name, schema, description, url_pattern, access, total_objects. |
| `describe_collection` | public | Detailed view of one collection — its properties with `indexed` / `filterable` / `sortable` flags + type. The agent uses this to learn what's queryable. |

### Content reads

| Tool | Access | What it does |
|---|---|---|
| `query_collection` | public | Paginated query against a collection's index. REST-style `include` / `exclude` / `sort` syntax. Limit caps at 50. |
| `search_collection` | public | Free-text search within a single collection. Drafts auto-hidden from anonymous callers. |
| `search_collections` | public | Cross-collection full-text search. Each result carries its `collection` for chaining into `get_object`. |
| `get_object` | public | Fetch one object by id. Drafts return "not found" to anonymous callers (doesn't leak existence). |
| `get_resource` | public | Resolve a `tcms://{collection}/{id}` URI — Phase 1 entry point for the Phase 2 resource model. |

### Admin

| Tool | Access | What it does |
|---|---|---|
| `get_site_info` | admin | Site name, version, edition, PHP version, installed extensions. Smoke test for "am I connected to the right site?" |
| `list_schemas` | admin | List every schema (id + description + category). |
| `get_schema` | admin | Fetch one schema as JSON — the same shape an operator writes into a schema file. |
| `create_schema` | admin | Save a new schema. Errors on reserved ids and id collisions. |
| `update_schema` | admin | Replace an existing schema definition. Idempotent (same input → same final state). |
| `delete_schema` | admin | **Destructive.** Refuses to delete reserved schemas, inherited schemas, or schemas still used by a collection. |
| `create_collection` | admin | Create a new collection bound to a schema. Errors on duplicate id. |
| `list_extensions` | admin | Every installed extension with id, name, enabled flag, capabilities. |
| `clear_cache` | admin | **Destructive.** Flush every available cache backend. Returns per-backend status. |

---

## Schema-level MCP config

Operators control AI exposure via two layers of MCP config — one per **collection**, one per **property**.

### Collection-level (`mcp` card on the collection editor)

```json
"mcp": {
  "access": "public",
  "description": "Public blog posts. Drafts are auto-hidden from anonymous callers.",
  "resource": true
}
```

| Field | Default | Meaning |
|---|---|---|
| `access` | `"admin"` | Who can call `query_collection` / `search_collection` / `get_object` against this collection. `"admin"` requires an API key; `"public"` allows anonymous AI agents. |
| `description` | empty | AI-targeted description shown in `list_collections` and the dynamic tool-description catalog. Falls back to the collection's general description if blank. |
| `resource` | `true` | When true, objects in this collection are addressable via `tcms://{collection}/{id}` URIs (Phase 2 resource model). |

### Property-level (MCP Details accordion on each property)

```json
"properties": {
  "content": {
    "type": "string",
    "field": "styledtext",
    "mcp": {
      "description": "The post body, rendered as markdown by default for AI consumption.",
      "expose": true
    }
  },
  "internal_notes": {
    "type": "string",
    "field": "textarea",
    "mcp": {
      "expose": false
    }
  }
}
```

| Field | Default | Meaning |
|---|---|---|
| `description` | falls back to `help` → `label` | AI-targeted description shown per property in `describe_collection` and tool-description catalogs. |
| `expose` | `true` | When `false`, this property is stripped from every MCP response entirely. Use for operator-only fields (credentials, internal references, supplier costs). |

Filterability and sortability are NOT operator-controlled — they're derived from:
1. The schema's `index` list (a non-indexed property can never be queried regardless of intent).
2. The property's field type (e.g. `text` and `id` are filterable by default; `styledtext` is not).

To make a property queryable, **add it to the schema's `index` array**. That's the lever.

### Reserved security defaults

The reserved `auth` schema ships with `mcp.expose: false` on:
- `password` (the password hash)
- `passkeys` (WebAuthn credential array)

These never appear in MCP responses regardless of persona, even if an operator marks the auth collection public. The defensive default belongs to T3, not to operators.

---

## Settings reference

Settings live in **Admin → Settings → MCP Server** and serialize to `mcp.*` under `tcms-data/.system/settings.json`.

| Key | Default | Effect |
|---|---|---|
| `mcp.enabled` | `true` | Master switch. When `false`, `POST /mcp` returns 404 and discovery reports `disabled: true`. |
| `mcp.publicAccess` | `false` | Default-deny for anonymous callers. When `false`, requests without an API key get 401 + `WWW-Authenticate: Bearer realm="MCP", error="login_required"`. |
| `mcp.allowedOrigins` | `[]` | CORS origin allow-list for browser-based AI clients (Phase 2 wires this). Empty = deny browser-based clients. |
| `mcp.publicIpPerMinute` | `60` | Per-IP rate limit on anonymous requests, 60-second window. API key callers bypass. Set to `0` to disable. |
| `mcp.toolPrefix` | `""` | Optional snake_case prefix prepended to every tool name (`bistro` → `bistro_list_collections`). Useful when an agent connects to multiple T3 sites simultaneously. |

Changing any of these triggers a session invalidation — active agent sessions get "session not found" on their next request and auto-reconnect with the new surface.

---

## API key authorization

MCP uses Total CMS's existing API key scope model — no parallel auth axis.

- **`paths: ["*"]`** (the default "All endpoints" choice) grants MCP automatically.
- **`paths: ["/mcp"]`** ("All MCP" sidebar option) grants MCP only.
- **Specifically-scoped keys** (e.g. `paths: ["/collections/blog"]`) must be edited to include `/mcp` — secure-by-default for any existing key.

Existing wildcard keys created before MCP shipped work unchanged.

---

## Filter / search syntax

`query_collection`'s `include` and `exclude` mirror the REST API's filter syntax exactly:

```json
{
  "collection": "blog",
  "include": "featured:true,category:tech",
  "exclude": "draft:true",
  "sort": "date:desc",
  "limit": 5
}
```

- Comma-separated `field:value` pairs.
- AND-semantics for `include`, OR-semantics for `exclude`.
- Wildcards: `*foo*` (contains), `foo*` (starts with), `*foo` (ends with).
- Public callers always get `draft:true` merged into `exclude` server-side — drafts can never leak.

`search_collection` and `search_collections` take a free-text `query`:

- Default AND across terms (`rust performance` matches items containing both).
- `or` between terms switches to OR semantics.
- `"quoted phrases"` match contiguously.

---

## Content rendering (`format` param)

Every tool that returns object data accepts an optional `format` parameter:

```json
{"collection": "blog", "id": "hello-world", "format": "markdown"}
```

| `format` | What you get for `styledtext` / `localizedstyledtext` properties |
|---|---|
| `markdown` (default) | Stored HTML converted to GitHub-flavored markdown. Friendliest for AI agents. |
| `html` | Raw stored HTML — pass-through. |
| `text` | HTML stripped to plain text with entities decoded. |

For `localizedstyledtext` (locale-keyed objects), each locale's HTML is converted independently and the keys preserved.

---

## CLI reference

```bash
# Show enabled state, edition gate, tool count by persona
tcms mcp:status

# Invoke a tool locally without going through the HTTP endpoint
tcms mcp:test query_collection --params='{"collection":"blog","limit":3}'

# Simulate an anonymous caller (default persona is admin)
tcms mcp:test query_collection --params='{"collection":"blog"}' --persona=public

# Machine-readable output
tcms mcp:status --json
tcms mcp:test list_collections --json
```

`tcms mcp:test` runs the tool directly against the registry — it doesn't hit the HTTP endpoint or the rate limiter. Useful for scripted smoke tests and CI.

---

## Extension authoring

Extensions can publish their own MCP tools and resources via `ExtensionContext`:

```php
// extension's boot.php
$context->registerMcpTool(
    name: 'acme_search_invoices',
    description: 'Search invoices by customer name or invoice number.',
    access: 'admin',
    handler: function (string $query, int $limit = 10) use ($context): array {
        $repository = $context->get(\Acme\Invoices\Repository\InvoiceRepository::class);
        return ['items' => $repository->search($query, $limit)];
    },
    inputSchema: [
        'type'     => 'object',
        'required' => ['query'],
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'Customer name or invoice number.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
        ],
    ],
);
```

**Collision policy: strict deny.** A tool whose name conflicts with a core tool OR another extension's tool is logged to `extensions.log` and skipped. Pick a vendor-prefixed name (`acme_*`) to avoid conflicts.

**Capabilities:** `mcp:tools` and `mcp:resources` appear in the Extensions admin page; operators can toggle them per extension.

The handler closure is invoked by the MCP SDK using PHP reflection on its named parameters — define typed `string` / `int` / `bool` / `array` params that map one-to-one with your `inputSchema` properties.

---

## Operations

### Rate limiting (G2)

Anonymous callers are throttled at `mcp.publicIpPerMinute` requests per IP per 60-second window. The counter routes through `CacheManager` — Redis on production installs that have it (cross-worker accurate), graceful fallback through APCu / Memcached / filesystem.

A 429 response includes `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Window` headers.

**Multi-worker caveat:** APCu-only installs see a per-worker counter, so effective limit ≈ `publicIpPerMinute × worker_count`. Configure Redis for accurate accounting.

### Activity log (G3)

Tool dispatch is logged to `tcms-data/logs/mcp-activity.log` at DEBUG level. Each call writes:

```
[2026-05-21T10:22:33-07:00] mcp-activity.DEBUG: Executing tool {"name":"query_collection","arguments":{"collection":"blog","limit":3}}
[2026-05-21T10:22:33-07:00] mcp-activity.DEBUG: Tool executed successfully {"name":"query_collection","result_type":"array"}
```

Tool errors land at ERROR level.

### Session invalidation (G5)

Active MCP client sessions cache the `tools/list` response from `initialize`. When any setting that affects the tool surface changes — `mcp.*` settings, a schema's `mcp.access` toggle, a per-property `mcp.expose` flip — sessions are dropped from `tmp/mcp-sessions/` and clients auto-reconnect on their next request.

The reconnect path is universally supported by conformant MCP clients. A future enhancement (Phase 1.5+) adds `notifications/tools/list_changed` for in-place refresh without the reconnect blip.

### `WWW-Authenticate` on 401 (G4)

Failed authentication returns:

```http
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="MCP", error="login_required"
```

`error="login_required"` for absent credentials (no API key + `mcp.publicAccess: false`); `error="invalid_token"` for bad/insufficient credentials. MCP hosts use this to pick the right lazy-auth UX.

---

## Anthropic Directory submission checklist

Before submitting your site to Anthropic's Connector Directory, walk through:

- [ ] HTTPS enforced (production deployment).
- [ ] `WWW-Authenticate` header returned on 401 — verified in this implementation.
- [ ] Discovery JSON published at `/.well-known/mcp.json`.
- [ ] Every tool has full annotations (`title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) — Total CMS ships these on every core tool.
- [ ] Tool names ≤ 64 characters (including any `toolPrefix`).
- [ ] Read and write operations split into separate tools (no mixed-mode tools).
- [ ] Tool descriptions don't instruct Claude ("always do X", "you must call Y first") — Total CMS uses `setInstructions()` for cross-tool guidance.
- [ ] Tools return MCP tool errors (`isError: true`) with recovery hints, not exceptions.
- [ ] Lazy authentication verified: public tools work unauthenticated; only protected tools challenge.
- [ ] Submission slug chosen carefully (fixed after publication).

---

## Security considerations

- **Anonymous access is default-deny.** `mcp.publicAccess: false` and `mcp.access: 'admin'` on every reserved schema mean a fresh install never leaks content until the operator opts a collection in.
- **Drafts are server-filtered.** Public callers can never see `draft:true` items regardless of caller intent — `query_collection`, `search_collection`, and `get_object` enforce this server-side.
- **Public registration carries automatic login.** Forms that use the public registration endpoint auto-log the new user in; gate them with CAPTCHA / rate limit / email verification when the access group new users land in reaches sensitive content. (Unrelated to MCP directly, but worth flagging — the same operator who exposes a collection to MCP might also be running public registration.)
- **API keys are scoped.** A key scoped only to `/collections/blog` does NOT unlock MCP; the operator must explicitly include `/mcp` (or `*`) in the scope.
- **No prompt-injection mitigation at the tool layer.** Content stored in `styledtext` fields is returned to the agent verbatim (after format conversion). Operators with untrusted user-generated content should sanitize at write time, not rely on MCP-side filtering.

---

## What's deferred to later phases

- **Phase 2:** `tcms://` resource list/subscribe model, CORS allow-list UI.
- **Phase 3:** Custom tools defined entirely in schema JSON (no PHP), SSE streaming for long content.
- **Phase 4:** OAuth 2.1 + PKCE flow, per-token scopes, per-token rate limits, customer-visible activity dashboard.
- **Phase 5:** Semantic search providers, MCP prompts.

See the planning notes in `docs/planning/mcp-server.md` for the full forward roadmap.
