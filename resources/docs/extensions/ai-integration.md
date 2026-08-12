---
title: "Using Total CMS with AI"
description: "Connect AI coding agents to Total CMS documentation via the MCP server for accurate Twig functions, filters, field types, and API endpoint lookups."
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

## How It Works

The MCP server maintains a structured index of all Total CMS documentation. When your AI agent calls a tool, it queries this index and returns precise, formatted results. The index is rebuilt whenever the documentation is updated, so results are always current.

This complements the `llms.txt` file at `docs.totalcms.co/llms.txt`, which provides a static overview. The MCP server is interactive — the AI calls it on demand during a task rather than reading the entire documentation upfront.

## Tips for Best Results

- **Be specific with function names.** `docs_lookup("twig_function", "cms.collection.objects")` gives better results than searching for "objects".
- **Use search for broad questions.** `docs_search("pagination")` will find relevant pages across all documentation.
- **Partial matches work.** If you look up `docs_lookup("field_type", "image")`, it will suggest `image-gallery` as a candidate match.
- **Include the namespace.** Twig functions in the `cms.*` namespace should include the full path: `cms.collection.objects`, not just `objects`.
- **List first, then look up.** Call `docs_lookup("cli_command")` with no `name` to see every command, then look up the one you need.
