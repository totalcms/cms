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
updated: 2026-05-22
---

# MCP Server

Every Total CMS site is an MCP server out of the box. Point Claude Code, Claude Desktop, ChatGPT, or any conformant MCP client at `https://your-site/mcp` and an AI agent can query your collections, fetch objects, search content, and (with an API key) manage schemas and collections.

The MCP server is **Pro+ edition only** and shipped with Total CMS 3.5.

---

## What is MCP?

The Model Context Protocol is Anthropic's open standard for how AI agents talk to tools and data sources. An MCP server publishes a set of **tools** (callable functions with typed inputs/outputs) and **resources** (addressable content URIs); the AI host (Claude, etc.) lets the agent invoke them.

For Total CMS specifically, MCP gives agents:

- **Discovery** — `list_collections`, `describe_collection`, `list_views`, `describe_view` map the site's content shape and pre-computed views.
- **Read** — `query_collection`, `search_collection`, `get_object`, `query_view`, `get_view` fetch content with the same filter/sort syntax as the REST API.
- **Resources** — `tcms://{collection}/`, `tcms://{collection}/{id}`, and `tcms://view/{id}` URIs the agent can address by URI, subscribe to, and re-fetch across sessions.
- **Write (admin)** — `create_schema`, `update_schema`, `delete_schema`, `create_collection`, `clear_cache`, `list_extensions`, `get_site_info` for operator-driven workflows from inside the agent.

---

## Three audiences, one endpoint

The same `/mcp` URL serves three personas; the tool surface scales per caller:

| Persona | How they authenticate | What they see |
|---|---|---|
| **Developer / operator** (`admin`) | `X-API-Key: <admin-key>` header on every request | Every tool — including the admin write tools. Same surface as the admin UI. |
| **Authenticated consumer** (`authenticated`) | `Authorization: Bearer <oauth-token>` header with at least one `mcp:*` scope | Tools marked `access: public` plus collections with `mcp.access: 'authenticated'` or `'public'`. Used by "Connect Claude" / "Connect Cursor" style flows where an end-user grants a third-party AI client scoped access to their content. See [OAuth Server](docs/apis/oauth) for setup. |
| **Public AI agent** (`public`) | No credentials (anonymous) | Only tools marked `access: public` AND only collections with `mcp.access: 'public'`. Drafts are auto-hidden. |

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

> **Server firewalls block MCP clients.** WAF rulesets that filter by user-agent (7G/8G firewall, some security plugins and host-level rules) 403 non-browser clients — which is every MCP client: claude.ai connectors, Claude Desktop, Claude Code, and `curl` alike. The symptom is an endpoint that works in a browser but fails from clients — a connector that errors or falls back to asking for manual credentials, or `curl` returning 403 with the default user-agent. Exempt `/mcp`, `/oauth/*`, and `/.well-known/*` from user-agent filtering (or drop the UA rules) before debugging anything else.

---

## Connecting an AI client via OAuth

API keys and OAuth both authenticate `/mcp` requests, but they serve different trust relationships. Choose based on who is operating the tool.

**Use an API key** for your own development tooling: Claude Code pointed at your site during a content project, a Stacks workflow that runs on your machine, or an admin script you control end-to-end. The key lives in your config file and never touches a consent screen.

**Use OAuth** when you ship a "connect to Claude" experience to end-users — your customers, your clients, team members who do not have T3 admin credentials. OAuth issues each person a scoped token through a consent screen; you can revoke individual connections without changing any shared secret; and every token action is logged to the OAuth activity log so you have a record.

### Prerequisites

- **Pro edition** — OAuth is a Pro+ feature. Trials count.
- **Keys generated.** Run `tcms oauth:setup` once if you haven't already.
- **OAuth server enabled.** Toggle on in **Admin → Settings → OAuth Server**.
- **Read the full OAuth setup guide** at [OAuth Server](docs/apis/oauth) before continuing — it covers key generation, client creation, scope definitions, and dynamic registration.

### Required scopes for MCP

A Bearer token must carry at least one `mcp:*` scope to do anything useful at `/mcp`. Tokens without any `mcp:*` scope receive a 403 on every request — even for collections the token's `cms:read` scope would otherwise cover.

| Scope | What it unlocks at `/mcp` |
|---|---|
| `mcp:tools` | Authorize all `tools/call` requests. Every persona-filtered tool the token's collection scopes can reach becomes callable. |
| `mcp:resources` | Authorize `resources/read`, `resources/list`, `resources/templates/list`, and `resources/subscribe`. Without this scope, resource methods return 403. |
| `mcp:search` | Currently inherits from `mcp:tools` — a token with `mcp:tools` can call `search_collection` and `search_collections`. The scope is reserved separately so it can be gated independently in a future release. |
| `mcp:prompts` | Authorize `prompts/list` and `prompts/get`. Prompts are visible in `prompts/list` but the content is withheld until the token carries this scope. |

A typical "read-only AI browser" connection requests `cms:read mcp:tools mcp:resources`. A connection that also needs prompts adds `mcp:prompts`. Grant the minimum scopes for the use case.

### Configuring a static client for Claude Desktop

Dynamic registration is **off by default** (an unauthenticated endpoint that writes server state — see the toggle's help text for the trade-off). Turn it on in **Admin → Settings → OAuth Server → Allow Dynamic Registration** for the zero-touch Claude flow: until it's on, the discovery document doesn't advertise `registration_endpoint`, and clients either report the server as incompatible (Claude Code) or ask for a manual Client ID and secret (claude.ai connectors). If you'd rather not enable it — or want a named client you can track and revoke independently — create a static client instead:

1. **Admin → Utilities → OAuth Clients → Create Client.**
2. Name it something traceable: "Claude Desktop – Joe", "Cursor – Content Team".
3. Add `http://localhost/` as a redirect URI (the `mcp-remote` shim binds a local loopback server for the code exchange).
4. Check `mcp:tools`, `mcp:resources`, and `mcp:prompts` under Scopes.
5. Save and copy the **Client ID** (UUID). The client secret is not needed — you'll pass only the client ID to `mcp-remote`.

Then add your site to Claude Desktop's `claude_desktop_config.json`:

```json
{
	"mcpServers": {
		"my-site": {
			"command": "npx",
			"args": [
				"-y",
				"mcp-remote",
				"https://your-site.example.com/mcp",
				"--client-id",
				"<paste-client-id-here>"
			]
		}
	}
}
```

Replace `https://your-site.example.com/mcp` and `<paste-client-id-here>` with your values. `mcp-remote` is the bridge shim that handles the OAuth authorization-code flow on behalf of native clients that don't have a built-in browser.

### What the end-user sees on first connection

1. Claude Desktop starts `mcp-remote`, which opens a browser tab pointing at `https://your-site.example.com/oauth/authorize`.
2. The user logs in with their T3 account (or is already logged in).
3. The consent screen lists the requested scopes in plain language. The user clicks **Allow**.
4. `mcp-remote` completes the PKCE code exchange, stores the access and refresh tokens in its local token cache, and the MCP session initializes.
5. Subsequent connections are silent — `mcp-remote` refreshes the access token automatically until the refresh token expires or the operator revokes the grant.

### Revoking access

Navigate to **Admin → Utilities → OAuth Grants**. Every active grant is listed with its client name and the scopes it holds. Click **Revoke** to immediately invalidate the access and refresh tokens for that connection. The next tool call from that client returns 401 and the user is prompted to re-authorize.

Deleting the client in **Admin → Utilities → OAuth Clients** cascades — all grants for that client are revoked at once. Useful when you retire a shared client that multiple users connected through.

---

## Tool catalog

All tool descriptions are also visible to the AI client at runtime via `tools/list`. The catalog below is the canonical reference.

### Discovery

| Tool | Access | What it does |
|---|---|---|
| `list_collections` | public | Persona-filtered overview of every collection. Returns id, name, schema, description, url_pattern, access, total_objects. |
| `describe_collection` | public | Detailed view of one collection — its properties with `indexed` / `filterable` / `sortable` flags + type + resolved property descriptions. The agent uses this to learn what's queryable. |
| `list_views` | public | Persona-filtered catalog of pre-computed data views. Returns id, name, description, last_built, access per view. |
| `describe_view` | public | Metadata + inferred output shape for a single view, sampled from the first cached item. Admin persona also receives the view's Twig `definition`. |

### Content reads

| Tool | Access | What it does |
|---|---|---|
| `query_collection` | public | Paginated query against a collection's index. REST-style `include` / `exclude` / `sort` syntax. Limit caps at 50. |
| `search_collection` | public | Free-text search within a single collection. Drafts auto-hidden from anonymous callers. |
| `search_collections` | public | Cross-collection full-text search. Each result carries its `collection` for chaining into `get_object`. |
| `get_object` | public | Fetch one object by id. Drafts return "not found" to anonymous callers (doesn't leak existence). |
| `query_view` | public | Paginated query against a data view's cached result. Same REST-style filter/sort vocabulary as `query_collection`. Limit caps at 50. |
| `get_view` | public | Fetch a data view's full cached result, capped at 50 items. Larger views emit a hint pointing at `query_view`. |
| `get_resource` | public | Resolve a `tcms://{collection}/{id}` URI to its underlying object. Sibling to the SDK's `resources/read` transport method — same data, different access path. |

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
| `create_object` | admin | Create a content object in a collection. Runs the same `ObjectSaver` path as the admin form — schema validation, slug generation, events. See *Binary fields* below. |
| `update_object` | admin | Replace a content object by id. Idempotent. Full replace, not a partial merge — send the whole object. See *Binary fields* below. |
| `list_extensions` | admin | Every installed extension with id, name, enabled flag, capabilities. |
| `clear_cache` | admin | **Destructive.** Flush every available cache backend. Returns per-backend status. |

#### Binary fields on `create_object` / `update_object`

Image, file, gallery, and depot fields can't be written through MCP — they need an upload pipeline (multipart bodies, storage handles) that a JSON tool call doesn't carry. The tools handle this at the **payload level**, not the schema level:

- A collection that merely *contains* a binary field is fully writable — just **omit** those fields from your payload. On `create_object` they're left unset; on `update_object` they keep their current value (the update never wipes an image you didn't touch).
- If the payload actually sets a non-empty value on a binary field, the call is refused with an error naming the offending fields. Drop them and retry, or edit those fields in the admin UI.

This means content-rich collections (blog posts with an optional hero image, etc.) work end-to-end from an agent. Set binary fields afterward in the admin UI, or via the admin clone feature.

> Reading objects with `get_object` / `query_collection` returns binary fields too. If you fetch an object, edit a text field, and send it back to `update_object`, strip the binary fields first (or blank them) — otherwise the call is refused.

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
| `resource` | `true` | When true, the collection is exposed as a `tcms://{collection}/` resource and its objects via the `tcms://{collection}/{id}` template. Set to `false` if you want the collection in tools but not in `resources/list`. |

The same `mcp` card lives on each **data view** (in the dataviews editor) with identical fields. A view marked `mcp.access: 'public'` shows up in `list_views` for anonymous callers and is fetchable at `tcms://view/{id}`.

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
| `expose` | `true` (see below) | When `false`, this property is stripped from every MCP response entirely. Use for operator-only fields (credentials, internal references, supplier costs). **Defaults to `false` on `password` and `secret` fields** — explicit operator opt-in with `mcp.expose: true` is required to surface a sensitive field. |

Filterability and sortability are NOT operator-controlled — they're derived from:
1. The schema's `index` list (a non-indexed property can never be queried regardless of intent).
2. The property's field type (e.g. `text` and `id` are filterable by default; `styledtext` is not).

To make a property queryable, **add it to the schema's `index` array**. That's the lever.

### Reserved security defaults

Two layers of defensive defaults protect credential-shaped data:

1. **Field-type default.** Any property using the `password` or `secret` field type is treated as non-exposed unless the operator explicitly sets `mcp.expose: true` on it. Catches the common case (hashed passwords, API keys, OAuth tokens stored via SecretField) for every custom schema without requiring per-schema opt-out.
2. **Explicit schema opt-out.** The reserved `auth` schema additionally ships with `mcp.expose: false` on `password` (redundant with the field-type default but kept for defense in depth and reader clarity) and on `passkeys` (a `hidden`-type array of WebAuthn credentials — the field-type default doesn't cover it, so the explicit entry is load-bearing).

Both layers are honored regardless of persona — even if an operator marks the auth collection public, these properties stay stripped.

---

## Settings reference

Settings live in **Admin → Settings → MCP Server** and serialize to `mcp.*` under `tcms-data/.system/settings.json`.

| Key | Default | Effect |
|---|---|---|
| `mcp.enabled` | `true` | Master switch. When `false`, `POST /mcp` returns 404 and discovery reports `disabled: true`. |
| `mcp.publicAccess` | `false` | Default-deny for anonymous callers. When `false`, requests without an API key get 401 + `WWW-Authenticate: Bearer realm="MCP", error="login_required"`. |
| `mcp.allowedOrigins` | `[]` | CORS origin allow-list for **browser-rendered** AI clients. Empty = browsers blocked. See [CORS and browser AI clients](#cors-and-browser-ai-clients) below. |
| `mcp.publicIpPerMinute` | `60` | Per-IP rate limit on anonymous requests, 60-second window. API key callers bypass. Set to `0` to disable. |
| `mcp.toolPrefix` | `""` | Optional snake_case prefix prepended to every tool name (`bistro` → `bistro_list_collections`). Useful when an agent connects to multiple T3 sites simultaneously. |
| `mcp.subscriptionsEnabled` | `true` | Master switch for resource subscriptions. When `false`, `resources/subscribe` still succeeds at the protocol level but T3 won't push `notifications/resources/updated` when content changes. See [Resource subscriptions](#resource-subscriptions). |

Changing any of these triggers a session invalidation — active agent sessions get "session not found" on their next request and auto-reconnect with the new surface.

---

## Resources

Resources are MCP's URI-addressable content primitive. Where tools are imperative (`query_collection({name: "blog"})`), resources are declarative — every resource has a stable URI an agent can bookmark, fetch, and re-fetch across sessions.

Total CMS exposes three URI shapes:

| URI | What it is | Registered when |
|---|---|---|
| `tcms://{collection}/` | Collection summary — recent items, capped at 50 | Every collection with `mcp.resource: true` (default) |
| `tcms://{collection}/{id}` | Single object | Same; registered as a *template*, not enumerated per-object |
| `tcms://view/{id}` | A data view's cached result | Every data view with `mcp.resource: true` |

Agents address these via three SDK transport methods:

- **`resources/list`** — flat list of concrete resources (collection summaries + per-view resources). Persona-filtered.
- **`resources/templates/list`** — list of URI templates (`tcms://{collection}/{id}`, `tcms://view/{id}`). Agents use templates to construct concrete URIs from known ids.
- **`resources/read`** — fetch the content at a URI. Returns the same data the equivalent tool would (`get_object` / `get_view`); `tcms://{collection}/` returns recent-item summaries.

The `get_resource` tool is an in-tool-flow alias for `resources/read` — handy when a URI lives in another tool's output (a recommendation, a search result) and the agent wants to dereference it inline.

### Resource enumeration is sparse on purpose

A site with 50k blog posts does NOT register 50k resource entries. `resources/list` returns one entry per collection (`tcms://blog/`); the template (`tcms://blog/{id}`) tells agents "this URI shape exists, plug in any blog post id." Concrete per-object URIs are dereferenced on demand via `resources/read`, which delegates to the same persona-aware code path as `get_object`.

Data views are different — each view IS independently named, so each gets its own concrete `tcms://view/{id}` entry in `resources/list`. The shared template still appears in `resources/templates/list` for admin agents.

---

## Resource subscriptions

Subscribed agents get pushed `notifications/resources/updated` events when content behind a URI changes — no polling required.

### How it works

1. Agent calls `resources/subscribe` with a `tcms://{collection}/` or `tcms://view/{id}` URI.
2. Total CMS records the subscription in `tmp/mcp-subscriptions.json` (a reverse index keyed by URI).
3. When any `object.created` / `object.updated` / `object.deleted` event fires for the collection, T3's listener walks the index, finds subscribed sessions, and pushes a JSON-RPC notification into each session's outbox file.
4. The subscriber's open SSE connection drains the outbox on its next loop tick (typically ~100ms latency) and the agent host surfaces the change.

### What it does NOT do

- **No per-object subscriptions** in 3.5. You subscribe to `tcms://blog/`, not `tcms://blog/hello-world`. Per-object granularity is a candidate for 3.5.x if customer demand surfaces.
- **No notification storms during imports.** T3's EventDispatcher auto-suspends `object.*` events for collections mid-import (e.g. JumpStart bulk loads fire `import.*` events instead, which the listener doesn't subscribe to). Bulk operations produce zero subscription notifications by design.
- **Within-request coalescing only.** A 1-second per-`(session, uri)` window collapses duplicate notifications. Across-request coalescing isn't implemented — agents may see multiple notifications when sweeps span requests.

### Per-view subscriptions

Data views are the bounded exception to the "collection-level only" rule. Subscribing to `tcms://view/{id}` notifies on every successful `DataViewBuilder::buildView` for that specific view (the builder calls `ObjectUpdater::updateObject` at the end of every rebuild, which fires `object.updated` on the `dataviews` collection — the listener routes those to per-view URIs instead of `tcms://dataviews/`).

### Kill switch

Set `mcp.subscriptionsEnabled: false` to disable push entirely. The SDK still accepts subscribe calls (so non-conformant clients that error on rejected subscriptions don't break) but T3 stops fanning out notifications. Useful when a noisy listener interferes with a deploy or migration.

---

## CORS and browser AI clients

CORS is a **browser-only** enforcement mechanism. It does nothing for server-side or native clients.

| Client | Does CORS apply? |
|---|---|
| Claude Desktop, Cursor, Claude Code, MCP Inspector (native apps) | **No.** They make raw HTTP requests; the browser security model never enters. |
| Server-side AI integrations (your own agent on a backend) | **No.** |
| Claude.ai web UI, ChatGPT.com, Google Gemini, Mistral Le Chat, etc. (browser-rendered) | **Yes.** The browser refuses the call unless T3 returns `Access-Control-Allow-Origin: <their-origin>`. |

So `mcp.allowedOrigins` is only meaningful if your customer plans to use a browser-rendered AI client against your site. Native and server-side AI works regardless.

### Configuring origins

The settings UI ships clickable presets for the well-known browser AI clients (Claude.ai, ChatGPT, Google Gemini, Microsoft Copilot, Mistral Le Chat, Perplexity) plus a wildcard option. Operators can also type custom origins for in-house playgrounds.

```
Admin → Settings → MCP Server → CORS Allowed Origins
```

**Default-deny.** Empty list = no `Access-Control-Allow-Origin` header sent = browsers block. Adding an origin echoes it back on matching requests. The wildcard (`*`) echoes the request's `Origin` for any caller — use with caution on sites that have any non-public MCP collections, since it opens your public MCP surface to any website's JavaScript.

Preflight `OPTIONS` requests short-circuit to a 204 with the standard handshake headers; the rate limiter doesn't see them so a single browser tab can perform a normal session without burning the public-IP quota on preflights.

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
# Show enabled state, edition gate, and the tool list per persona.
# Saved-query tools from collection MCP cards are included and
# annotated "(saved query)"; --json lists them in `schema_tools`.
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

Extensions can publish their own MCP tools and resources via `ExtensionContext::registerMcpTool()`, `registerMcpResource()`, and `registerMcpResourceTemplate()`. Custom tools and resources show up alongside the core surface — AI agents see them the same way they see `query_collection` or `tcms://blog/`.

See **[Extending MCP](mcp/extensions)** for the full authoring guide, including registration examples, naming conventions, capability toggles, and real-world use cases.

---

## Operations

### Rate limiting (G2)

Anonymous callers are throttled at `mcp.publicIpPerMinute` requests per IP per 60-second window. The counter routes through `CacheManager` — Redis on production installs that have it (cross-worker accurate), graceful fallback through APCu / Memcached / filesystem.

A 429 response includes `Retry-After`, `X-RateLimit-Limit`, and `X-RateLimit-Window` headers.

**Multi-worker caveat:** APCu-only installs see a per-worker counter, so effective limit ≈ `publicIpPerMinute × worker_count`. Configure Redis for accurate accounting.

**Reverse-proxy rate limits:** This is T3's *application*-layer throttle for anonymous callers. If you also rate-limit at the web server (Nginx `limit_req`, etc.), do **not** put the MCP endpoint in the same low-rate zone you use for admin login — agents batch tool calls in parallel and will hit a 1 req/s login zone instantly, surfacing as timeouts rather than clean 429s. Give `/mcp` its own generous zone with a healthy burst. See [Nginx → Rate limiting the MCP endpoint](docs/operations/nginx) for a worked config.

### Activity log (G3)

Tool dispatch is logged to `tcms-data/.system/logs/mcp.log` under the `mcp-activity` channel at DEBUG level. Each call writes:

```
[2026-05-21T10:22:33-07:00] mcp-activity.DEBUG: Executing tool {"name":"query_collection","arguments":{"collection":"blog","limit":3}}
[2026-05-21T10:22:33-07:00] mcp-activity.DEBUG: Tool executed successfully {"name":"query_collection","result_type":"array"}
```

Tool errors land at ERROR level.

### Session invalidation (G5)

Active MCP client sessions cache the `tools/list` response from `initialize`. When any setting that affects the tool surface changes — `mcp.*` settings, a schema's `mcp.access` toggle, a per-property `mcp.expose` flip — sessions are dropped from `tmp/mcp-sessions/` and clients auto-reconnect on their next request.

The reconnect path is universally supported by conformant MCP clients. A future enhancement will add `notifications/tools/list_changed` push for in-place refresh without the reconnect blip.

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

## What shipped in 3.5

The full MCP server, including all of the following, ships in 3.5:

- **Core MCP server** — `/mcp` endpoint, three personas, full tool catalog ([this page](docs/mcp/server)).
- **MCP resources** — collection / data-view URIs and `resources/subscribe` with SSE notifications.
- **Saved-query tools** — JSON-defined query tools per collection, no PHP needed ([Saved-Query Tools](docs/mcp/saved-query-tools)).
- **MCP prompts** — templated workflows like "draft a blog post in our voice" or "audit broken links" ([Prompts](docs/mcp/prompts)).
- **OAuth 2.1 + PKCE** — full authorization-code flow, scoped tokens, customer-visible activity dashboard, the "connect Joe's Bistro to Claude" path ([OAuth Server](docs/apis/oauth)).
- **Pluggable search providers** — extension hook for swapping the built-in text search for Algolia, Meilisearch, etc.; built-in `text` provider always available.

## What's deferred

- **SSE streaming for long tool responses** — currently every response is a single JSON-RPC reply.
- **Object-level resource subscriptions** (subscribe to a single `tcms://blog/post-1` rather than the collection) — deferred indefinitely; the collection-level model meets the typical agent's needs.
- **MCP sampling** — letting the server request inference from the client. Not on the near-term roadmap.

See the planning notes in `docs/planning/mcp-server.md` for the full forward roadmap.
