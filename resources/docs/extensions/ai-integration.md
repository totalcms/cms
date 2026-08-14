---
title: "Using Total CMS with AI"
description: "Connect AI coding agents to Total CMS documentation via the MCP server for accurate Twig functions, filters, field types, and API endpoint lookups."
related:
  - mcp/docs-tools
  - extensions/bundled
---

# Using Total CMS with AI

Total CMS ships a built-in MCP (Model Context Protocol) server — and the official docs connector is just that server, running on [totalcms.co](https://totalcms.co) itself. Point your AI coding agent at it for real-time access to the complete Total CMS documentation. Instead of relying on training data that may be outdated or incomplete, your AI agent can look up exact function signatures, filter syntax, field configuration options, and API endpoints on demand.

## What is MCP?

MCP is an open protocol that lets AI tools connect to external data sources. When you configure an MCP server in your AI tool, the agent can call it mid-task to get accurate, current information. This means fewer hallucinated function names and correct parameter lists the first time.

## Setup

Add the Total CMS MCP server to your AI tool's configuration. This is a one-time setup that works across all your Total CMS projects.

### Claude Code

Add to `~/.claude/mcp.json`:

```json
{
  "mcpServers": {
    "totalcms-docs": {
      "url": "https://totalcms.co/mcp"
    }
  }
}
```

### Cursor

Add to your Cursor MCP settings (Settings > MCP Servers):

```json
{
  "mcpServers": {
    "totalcms-docs": {
      "url": "https://totalcms.co/mcp"
    }
  }
}
```

### VS Code (GitHub Copilot)

Add to your VS Code settings (`.vscode/mcp.json` in your project, or user settings):

```json
{
  "servers": {
    "totalcms-docs": {
      "url": "https://totalcms.co/mcp"
    }
  }
}
```

### Windsurf

Add to your Windsurf MCP configuration:

```json
{
  "mcpServers": {
    "totalcms-docs": {
      "url": "https://totalcms.co/mcp"
    }
  }
}
```

No API key or authentication is required. The server is publicly accessible.

## Available Tools

Once configured, your AI agent has access to these documentation tools:

| Tool | What it does |
|---|---|
| `docs_search(query, limit)` | Full-text search across all Total CMS documentation |
| `docs_get(path)` | Full page markdown for a known documentation path |
| `docs_lookup(kind, name)` | Reference lookup for Twig functions/filters, field types, API endpoints, schema config, CLI commands, extension API, and builder API |

### docs_search

Full-text search across all Total CMS documentation. Use this for general questions.

```
docs_search("image watermark configuration")
docs_search("how to filter collections by date")
```

### docs_get

Fetch the full markdown for a documentation page by its path. Use it once `docs_search` has pointed you at a page and you need the complete content, not just an excerpt.

```
docs_get("site-builder/overview")
docs_get("twig/functions")
```

### docs_lookup

Look up a specific reference entry by `kind`: Twig functions, Twig filters, field types, REST API endpoints, schema configuration keys, CLI commands, extension API, or builder API. Returns the exact signature, description, and usage examples. Omit `name` to list every entry of that kind.

```
docs_lookup("twig_function", "cms.collection.objects")
docs_lookup("twig_filter", "humanize")
docs_lookup("field_type", "image-gallery")
docs_lookup("api_endpoint", "GET /api/collections")
docs_lookup("schema_config", "prettyUrl")
docs_lookup("cli_command", "schema:list")
```

Valid `kind` values: `twig_function`, `twig_filter`, `field_type`, `api_endpoint`, `schema_config`, `cli_command`, `extension_api`, `builder_api`.

### Resources

Resource-aware clients can also read documentation pages directly as MCP resources, addressed as `totalcms-docs://{group}/{page}` — for example `totalcms-docs://twig/functions`.

## Your own site ships these same tools, matched to its own version

`https://totalcms.co/mcp` always serves whatever's newest — useful while you're learning Total CMS generally, but it can drift from the exact version a given site is running. Every Total CMS 3.5+ install also ships `docs_search`, `docs_get`, and `docs_lookup` locally via the bundled `totalcms/docs` extension, reading straight out of that install's own `resources/docs/` — so if you're developing against a specific site, pointing your agent at *that site's* `/mcp` endpoint gets you documentation guaranteed to match the version it's actually running.

These tools are enabled out of the box but require authentication (an API key or an OAuth-authenticated connection) unless the operator has opted in to public exposure — they're not a drop-in replacement for the always-public `totalcms.co` connector on every site. See [Documentation Tools](docs/mcp/docs-tools) for how to connect, how an operator can expose them publicly, and how to turn them off.

## How It Works

The MCP server maintains a structured index of all Total CMS documentation. When your AI agent calls a tool, it queries this index and returns precise, formatted results. The index is rebuilt whenever the documentation is updated, so results are always current.

This complements the `llms.txt` file at `docs.totalcms.co/llms.txt`, which provides a static overview. The MCP server is interactive — the AI calls it on demand during a task rather than reading the entire documentation upfront.

## Tips for Best Results

- **Be specific with function names.** `docs_lookup("twig_function", "cms.collection.objects")` gives better results than searching for "objects".
- **Use search for broad questions.** `docs_search("pagination")` will find relevant pages across all documentation.
- **Partial matches work.** If you look up `docs_lookup("field_type", "image")`, it will suggest `image-gallery` as a candidate match.
- **Include the namespace.** Twig functions in the `cms.*` namespace should include the full path: `cms.collection.objects`, not just `objects`.
- **List first, then look up.** Call `docs_lookup("cli_command")` with no `name` to see every command, then look up the one you need.
