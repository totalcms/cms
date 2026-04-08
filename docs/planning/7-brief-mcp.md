# Total CMS 3 — API MCP Extension Brief

**Status:** Planning (2026)
**Project number:** 7
**Depends on:** Extensions (Project 4), CLI (Project 2)
**Hosted at:** Per T3 install — `https://yoursite.com/mcp`

---

## Goal

Build a first-party T3 extension that registers an MCP-compatible endpoint on a running T3 instance. This allows an AI coding agent to directly interact with a specific T3 install — querying collections, inspecting schemas, managing content, and saving templates — without needing terminal or CLI access.

This is the complement to the Docs MCP Server (Project 1). The docs server gives an AI agent knowledge of T3. The API MCP extension gives it hands on a specific site.

---

## How It Relates to the CLI

These are not competing tools. They serve different developer surfaces and both call the same underlying T3 services:

| | CLI | API MCP Extension |
|---|---|---|
| **Best for** | Terminal workflows, CI/CD, shell scripts | Editor-integrated agents (Claude Code, Cursor) |
| **Auth** | Direct server access | API key over HTTPS |
| **Runs on** | The server itself | Developer's local machine, remote |
| **Requires terminal** | Yes | No |

A DevOps pipeline uses the CLI. A developer inside VS Code uses the MCP extension. The service layer underneath is shared — MCP is a thin adapter, not a reimplementation.

---

## Architecture

### Extension Structure

Ships as a first-party extension using the Extensions architecture (Project 4):

```
tcms-data/
    extensions/
        joeworkman/
            mcp/
                extension.json
                Extension.php
                src/
                    McpHandler.php
                    Tools/
                        SchemaTools.php
                        CollectionTools.php
                        SiteTools.php
                        TemplateTools.php
```

### How It Registers

The extension boots a single new API route on the T3 instance:

```
POST /mcp
```

This endpoint receives MCP protocol messages, dispatches them to the appropriate T3 service, and returns structured JSON responses. Authentication uses T3's existing API key system — the same keys already used for REST API access. No new auth infrastructure required.

### Extension Manifest

```json
{
    "id": "joeworkman/mcp",
    "name": "MCP Server",
    "version": "1.0.0",
    "requires": "totalcms>=3.5.0",
    "entry": "Extension.php",
    "permissions": [
        "api:routes",
        "collections:read",
        "collections:write",
        "schemas:read",
        "schemas:write",
        "templates:read",
        "templates:write",
        "cache:clear"
    ]
}
```

Permissions are shown to the user before install. Write permissions can be individually disabled in extension settings for read-only installs.

---

## MCP Tools

Tools map directly to CLI commands from Project 2. No new service layer — the same underlying classes handle both.

### Schema Tools

```
tcms_schema_list()
  → List all schemas with field counts and edition requirements

tcms_schema_get(name)
  → Full schema definition including all fields, settings, and locale config

tcms_schema_create(definition)
  → Create a new schema from a definition object
  → Returns the created schema or validation errors
```

### Collection Tools

```
tcms_collection_list()
  → List all collections with object counts and schema names

tcms_collection_query(collection, filters?, sort?, limit?, page?)
  → Query a collection with optional filtering, sorting, and pagination
  → Returns objects array plus pagination metadata

tcms_collection_get(collection, id)
  → Get a single object by ID
  → Returns full object with all field values

tcms_collection_create(collection, data)
  → Create a new object in a collection
  → Returns the created object including generated ID

tcms_collection_update(collection, id, data)
  → Update an existing object
  → Partial updates supported — only provided fields are changed
```

### Site Tools

```
tcms_site_info()
  → T3 version, edition, PHP version, active extensions, locale config
  → Useful as a first call to understand the environment

tcms_cache_clear()
  → Clear the T3 cache
  → Returns confirmation and cache backend used
```

### Template Tools
*Available only when Site Builder extension (Project 5) is also installed*

```
tcms_template_list()
  → List all templates, layouts, partials, and macros with paths

tcms_template_get(path)
  → Get the full content of a template file

tcms_template_save(path, content)
  → Save content to a template file
  → Creates the file if it does not exist
```

---

## Security

**Authentication**
- Every request requires a valid T3 API key via `X-API-Key` header
- Unauthenticated requests return 401 immediately
- Keys are managed in the existing T3 admin API keys section — no new UI required

**Permission scoping**
- MCP tools respect T3's existing access group permissions
- A key with read-only access cannot call write tools regardless of MCP request

**Write protection**
- Write operations (`tcms_collection_create`, `tcms_collection_update`, `tcms_schema_create`, `tcms_template_save`) can be disabled globally in extension settings
- Useful for production installs where an AI agent should only be able to read

**Rate limiting**
- Inherited from T3's existing REST API rate limiting
- No additional configuration required

---

## Developer Configuration

Per-project setup. The developer points their AI agent at their specific T3 instance using an API key generated in the T3 admin:

```json
{
  "mcpServers": {
    "totalcms-docs": {
      "url": "https://mcp.totalcms.co/sse"
    },
    "my-client-site": {
      "url": "https://clientsite.com/mcp",
      "headers": {
        "X-API-Key": "their-api-key"
      }
    }
  }
}
```

Both servers active simultaneously. The docs server supplies T3 knowledge. The API server supplies live site access. A developer working on multiple client sites adds one entry per site.

---

## Combined Workflow Example

With both MCP servers configured, a developer can drive an entire T3 setup from a single Claude Code prompt:

**Developer:** *"Set up a portfolio site with a case studies collection. Fields: title, client name, year, cover image, body text. Then write a Twig template that loops through them."*

**Agent workflow:**
1. Calls `docs_search("custom schema Pro edition")` → confirms field types available
2. Calls `tcms_site_info()` → confirms T3 edition and version on the live site
3. Calls `docs_field_type("image")` → gets exact image field config options
4. Calls `tcms_schema_create({...})` → creates the case studies schema on the live instance
5. Calls `docs_twig_function("cms.objects")` → gets exact loop syntax
6. Calls `docs_twig_filter("dateFormat")` → gets correct date formatting syntax
7. Writes the Twig template using correct T3 syntax
8. Calls `tcms_template_save("pages/portfolio.twig", content)` → saves it to the instance

Developer gets a working portfolio collection and template without opening the T3 admin or writing a line of Twig manually.

---

## Installation

```bash
# Via CLI
tcms extension:install joeworkman/mcp

# Via admin dashboard
# One-click install from marketplace browser
```

After install, the `/mcp` endpoint is immediately active. The developer generates an API key in the T3 admin and adds it to their agent config.

---

## What Done Looks Like

- Extension installs cleanly via CLI and admin dashboard
- `POST /mcp` endpoint responds correctly to MCP protocol messages
- `tcms_site_info()` returns accurate version and edition data
- `tcms_schema_list()` returns all schemas for the T3 instance
- `tcms_collection_query()` returns correctly filtered and paginated results
- `tcms_collection_create()` creates a real object and returns it
- `tcms_collection_update()` correctly applies partial updates
- `tcms_template_list()` returns all templates when Site Builder is installed
- `tcms_template_save()` writes content to the correct file
- Unauthenticated requests return 401
- A read-only API key cannot call write tools
- Disabling write operations in extension settings blocks all write tool calls
- Disabling the extension removes the `/mcp` endpoint with no other effect on T3
- A developer using both MCP servers can scaffold a complete schema and template from a single Claude Code prompt