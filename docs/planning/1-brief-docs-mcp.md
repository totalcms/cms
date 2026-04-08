# Total CMS 3 — Docs MCP Server Brief

**Status:** Planning (2026)
**Priority:** Ship first — no dependencies on any other T3 project
**Hosted at:** `mcp.totalcms.co`

---

## Goal

Build a lightweight MCP server that makes T3's documentation queryable by AI coding agents in real time. When a developer uses Claude Code, Cursor, or Windsurf to build a T3 site, the agent can look up exact Twig function signatures, field type options, API endpoint parameters, and CLI command flags instead of guessing from training data.

T3 already has `llms.txt` at `docs.totalcms.co/llms.txt`. That file is passive — an AI reads it if it happens to fetch it. This MCP server is active — the AI calls it on demand mid-task. Both stay in place. They serve different purposes.

---

## Why Ship This First

- No dependency on any T3 code changes
- Immediately useful to developers building T3 sites with AI tools today
- A weekend project in terms of implementation complexity
- Strong positioning: "T3 is AI-native" is a differentiator no competing flat-file CMS can currently claim
- Compounds over time — every developer who uses Claude Code with T3 gets a better experience than with any alternative

---

## Architecture

### Stack
A small standalone Node.js service. The MCP protocol is well-documented and the server surface is small. PHP is also viable but Node has better MCP library support right now.

### Index Strategy
The server builds a structured JSON index from `docs.totalcms.co` at deploy time. Three options in order of complexity:

- **Static JSON index** — parse docs into structured JSON at deploy time, query in memory. Simple, fast, no moving parts. Start here.
- **SQLite full-text search** — more powerful search, still no external dependencies. Upgrade path if search quality needs improvement.
- **Vector embeddings** — semantic search, adds infrastructure complexity. Not needed initially.

Start with the static JSON index. It covers 90% of the use case and the tool interface stays identical if you upgrade the backend later.

### Index Structure
Each doc entry in the index captures enough context for an AI to use it correctly:

```json
{
  "type": "twig_function",
  "name": "cms.objects",
  "signature": "cms.objects(collection, filters?, sort?, limit?, page?)",
  "description": "Returns all objects from a collection with optional filtering and pagination.",
  "parameters": [...],
  "returns": "array",
  "examples": [...],
  "edition": "lite",
  "url": "https://docs.totalcms.co/twig/cms-objects"
}
```

---

## MCP Tools

Seven tools covering the full T3 surface:

```
docs_search(query)
  → Full-text search across all T3 documentation
  → Returns matching sections with context and source URLs

docs_twig_function(name)
  → Exact signature, parameters, return type, usage examples
  → e.g. docs_twig_function("cms.objects")

docs_twig_filter(name)
  → Filter signature and usage examples
  → e.g. docs_twig_filter("dateFormat")

docs_field_type(name)
  → Field type options, schema config, admin behavior
  → e.g. docs_field_type("blog")

docs_api_endpoint(method, path)
  → REST endpoint parameters, headers, response shape
  → e.g. docs_api_endpoint("GET", "/api/collections/{name}")

docs_cli_command(name)
  → CLI command flags and usage examples
  → e.g. docs_cli_command("tcms push")

docs_schema_config(key)
  → Schema configuration options and valid values
```

---

## Hosting

Standalone service on a BSH VPS or existing infrastructure at `mcp.totalcms.co`. Publicly accessible — no authentication required for docs queries. The index rebuilds automatically on a schedule or via webhook when docs are updated.

---

## Developer Configuration

A developer adds the server to their AI agent config once and never touches it again:

**Claude Code / Cursor / Windsurf (`mcp.json`):**
```json
{
  "mcpServers": {
    "totalcms-docs": {
      "url": "https://mcp.totalcms.co/sse"
    }
  }
}
```

No per-project setup. No authentication. Works immediately for any T3 project they open.

---

## What Done Looks Like

- `mcp.totalcms.co` is live and responds to MCP protocol messages
- `docs_search("cms.objects pagination")` returns accurate, current documentation
- `docs_twig_function("cms.objects")` returns the exact parameter list and usage examples
- `docs_twig_filter("dateFormat")` returns correct filter syntax and options
- `docs_field_type("blog")` returns complete field configuration options
- `docs_api_endpoint("GET", "/api/collections/{name}")` returns correct parameters and response shape
- A developer using Claude Code with the server configured gets accurate T3-specific answers without hallucination
- `llms.txt` remains in place alongside MCP
- Documentation index rebuilds automatically when docs are updated
- Server handles concurrent requests without degrading

---

## Marketing Note

This is worth a dedicated announcement when it ships — not buried in a release note. "Build T3 sites faster with AI" with a short demo video showing Claude Code using the MCP server to scaffold a schema and write a template is a strong piece of content that reaches developers who have never heard of T3 before.