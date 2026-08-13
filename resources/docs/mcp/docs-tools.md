---
title: "Documentation Tools"
description: "The bundled totalcms/docs extension exposes docs_search, docs_get, and docs_lookup MCP tools so AI agents can query this install's own documentation, matched to its own version."
audience: intermediate
updated: 2026-08-13
related:
  - mcp/server
  - mcp/extensions
  - extensions/bundled
  - extensions/manifest
---

# Documentation Tools

Every Total CMS install ships a bundled extension, `totalcms/docs`, that exposes the documentation you're reading right now — `resources/docs/*.md`, plus the generated search and reference indexes — through this site's own MCP server as three tools: `docs_search`, `docs_get`, and `docs_lookup`. It's the successor to the standalone `mcp.totalcms.co` connector.

The reason it ships per-install instead of staying centralized: the docs corpus these tools read is the one that shipped with **this** codebase. An agent working against a site running 3.5.0-rc.12 gets rc.12's documentation — field names, tool catalogs, and behavior that matches what's actually installed — not whatever happens to be current on the public docs site. Version drift between "what the docs say" and "what the site does" stops being possible.

## The three tools

| Tool | Input | What it does |
|---|---|---|
| `docs_search` | `query` (string, required), `limit` (int, default 8, max 20) | Free-text search across every doc page. Returns matching pages with `path`, `title`, `group`, and matched section headings. |
| `docs_get` | `path` (string, required) | Fetch one page's full markdown by the `path` a `docs_search` result returned (e.g. `"twig/data"`, `"site-builder/overview"`). |
| `docs_lookup` | `kind` (enum, required), `name` (string, optional) | Structured lookup into the reference index: `twig_function`, `twig_filter`, `field_type`, `api_endpoint`, `schema_config`, `cli_command`, `extension_api`, or `builder_api`. Omit `name` to list every entry of that kind; a near-miss `name` returns close candidates instead of nothing. |

The intended flow is `docs_search` → `docs_get`: search returns paths, `docs_get` reads the page. `docs_lookup` is a shortcut for the cases where an agent already knows what it's after — a specific Twig function, field type, or CLI command — without searching prose first.

`docs_lookup` depends on `reference-index.json`, which ships alongside `search-index.json`. If an older install doesn't have it yet, `docs_lookup` returns a graceful error explaining that `docs_search` still works — it doesn't fail the whole extension.

No MCP resource template ships for the docs corpus — only tools. `docs_search` + `docs_get` cover the same ground in one round trip, without adding an enumerable resource surface for a corpus nobody would browse by URI.

## Enabled by default, invisible to anonymous callers

Unlike most bundled extensions (disabled until an operator opts in — see [Bundled Extensions](docs/extensions/bundled)), `totalcms/docs` ships `default_enabled: true`. A fresh install has all three tools live immediately, no admin action required.

That does **not** mean they're public. The tools register at `authenticated` access by default: visible to the admin persona (API key) and to any OAuth-authenticated caller, but absent from `tools/list` for anonymous callers. Listing and calling are gated separately — resolving to the authenticated persona needs any `mcp:*` scope, while actually invoking a tool needs `mcp:tools`. A token carrying only `mcp:resources` therefore sees these tools listed and is refused when it calls one. Installing or updating to a version that ships this extension changes nothing about a site's *public* MCP surface — `mcp.publicAccess` and each collection's `mcp.access` setting still govern what an anonymous agent can reach. See [Three audiences, one endpoint](mcp/server#three-audiences-one-endpoint) for how the personas work.

## Exposing the tools publicly

Some sites — a Total CMS docs mirror, a support site, an agency's own marketing site for the product — want an anonymous AI agent to be able to read the documentation without a login. For that case, the extension carries one setting:

**Admin → Extensions → Total CMS Docs → Settings → "Expose documentation tools to anonymous visitors"** (`publicTools`, default off).

Turning it on switches all three tools' access from `authenticated` to `public` for every caller, including unauthenticated ones.

Most sites should leave this off. Turn it on only if your site's actual anonymous audience is Total CMS builders — people who'd benefit from an AI agent being able to read your docs without logging in. On a typical customer site, there's no reason to widen the public MCP surface for a documentation corpus visitors don't need.

## Turning it off entirely

`totalcms/docs` is a normal bundled extension once installed — disable it like any other:

**Admin → Extensions → Total CMS Docs → Disable**, or:

```bash
tcms extension:disable totalcms/docs
```

This removes `docs_search`, `docs_get`, and `docs_lookup` from every persona's tool catalog immediately — admin included. As with other bundled extensions, disabling doesn't uninstall it; there's no **Remove** button, since it ships with the package. Re-enable at any time to bring the tools back.

Confirm the current state with:

```bash
tcms mcp:status
```

The tool list shown per persona reflects whether the extension is enabled and, if so, which access level it's currently registered at.

## See also

- [MCP Server](mcp/server) — personas, transport, the full core tool catalog
- [Extending MCP](mcp/extensions) — how extensions register their own tools and resources
- [Bundled Extensions](docs/extensions/bundled) — the bundled-extension model in general
- [Extension Manifest Reference](docs/extensions/manifest) — the `default_enabled` field this extension uses
