---
title: "Using Total CMS with AI"
description: "Connect AI coding agents to Total CMS documentation via the MCP server for accurate Twig functions, filters, field types, and API endpoint lookups."
---

# Using Total CMS with AI

Total CMS provides an MCP (Model Context Protocol) server that gives AI coding agents real-time access to the complete Total CMS documentation. Instead of relying on training data that may be outdated or incomplete, your AI agent can look up exact function signatures, filter syntax, field configuration options, and API endpoints on demand.

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
      "url": "https://mcp.totalcms.co/"
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
      "url": "https://mcp.totalcms.co/"
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
      "url": "https://mcp.totalcms.co/"
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
      "url": "https://mcp.totalcms.co/"
    }
  }
}
```

No API key or authentication is required. The server is publicly accessible.

## Available Tools

Once configured, your AI agent has access to these documentation lookup tools:

### docs_search

Full-text search across all Total CMS documentation. Use this for general questions.

```
docs_search("image watermark configuration")
docs_search("how to filter collections by date")
```

### docs_twig_function

Look up a specific Twig function by name. Returns the exact signature, parameters, return type, and usage examples.

```
docs_twig_function("cms.collection.objects")
docs_twig_function("cms.render.image")
docs_twig_function("selectOptions")
```

### docs_twig_filter

Look up a Twig filter. Returns the signature, description, and examples.

```
docs_twig_filter("humanize")
docs_twig_filter("truncateWords")
docs_twig_filter("dateFormat")
```

### docs_field_type

Look up field type configuration options and schema settings.

```
docs_field_type("image-gallery")
docs_field_type("styled-text")
docs_field_type("deck")
```

### docs_api_endpoint

Look up a REST API endpoint. Returns the HTTP method, path, parameters, and response shape.

```
docs_api_endpoint("GET", "/collections")
docs_api_endpoint("POST", "/collections/{collection}")
docs_api_endpoint("GET", "/collections/{collection}/{id}")
```

### docs_schema_config

Look up schema and collection configuration options.

```
docs_schema_config("labelPlural")
docs_schema_config("urlPattern")
docs_schema_config("manualSort")
```

## How It Works

The MCP server maintains a structured index of all Total CMS documentation. When your AI agent calls a tool, it queries this index and returns precise, formatted results. The index is rebuilt whenever the documentation is updated, so results are always current.

This complements the `llms.txt` file at `docs.totalcms.co/llms.txt`, which provides a static overview. The MCP server is interactive — the AI calls it on demand during a task rather than reading the entire documentation upfront.

## Tips for Best Results

- **Be specific with function names.** `docs_twig_function("cms.collection.objects")` gives better results than searching for "objects".
- **Use search for broad questions.** `docs_search("pagination")` will find relevant pages across all documentation.
- **Partial matches work.** If you search for `docs_field_type("image")`, it will suggest `image-gallery` as a match.
- **Include the namespace.** Twig functions in the `cms.*` namespace should include the full path: `cms.collection.objects`, not just `objects`.
