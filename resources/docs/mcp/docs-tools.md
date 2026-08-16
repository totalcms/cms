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

These work on **Standard and Pro**. The extension ships with the package on every install, but its tools are served by the site's own MCP endpoint — which Lite does not include — so an agent can only reach them from Standard upwards.

## The five workflow prompts

Tools give an agent the ability to read the docs; they don't tell it when to bother. The same extension registers five MCP prompts that do:

| Prompt | Arguments | What it does |
|---|---|---|
| `tcms_research` | `question` | Answer a Total CMS question from this install's docs rather than from the model's memory. Sets the retrieval order — `docs_lookup` for named symbols, `docs_search` → `docs_get` for everything else — and hands over Total CMS vocabulary so a missed search can be retried usefully. |
| `tcms_build_page` | `purpose` | Plan and build a Site Builder page: route, template, data, navigation. Leads with the fact that there is no build step. |
| `tcms_explain_field` | `field_type` | Explain a schema field type from the reference index, with a copy-pasteable declaration and how to read the value in Twig. |
| `tcms_twig_recipe` | `goal` | Find the right Twig function or filter and write a complete template, with signatures verified rather than guessed. |
| `tcms_troubleshoot_mcp` | `symptom`, `site_url` (optional) | Diagnose an MCP connection failure, walking the known causes in the order they actually occur. |

They're named `tcms_*` rather than `docs_*` deliberately. The tools are documentation operations; these are product workflows that *use* the documentation, and `tcms` is already this server's namespace (the `tcms://` resource URIs). The distinctive prefix also matters mechanically: a prompt in your `mcp-prompt` collection always wins over an extension-registered one of the same name, so a generic name like `explain_field` would let an operator's own prompt silently replace this one.

Prompt access follows the tools. A prompt instructing an agent to call `docs_lookup` is useless to a caller who can't see `docs_lookup`, so both move together when you change the setting below.

The prompt text lives in `prompts.json` inside the extension, not in its PHP. It ships as part of the package, so local edits are overwritten on update — to customise the wording for your site, create a prompt in the `mcp-prompt` collection with the same name and yours takes precedence.

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
