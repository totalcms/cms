---
title: "MCP Saved-Query Tools"
description: "Define parameterized AI-callable query tools for a collection using JSON — no PHP required."
audience: intermediate
updated: 2026-05-23
related:
  - mcp/server
  - mcp/extensions
---

# MCP Saved-Query Tools

Saved-query tools are JSON-defined, parameterized queries scoped to a collection. They appear as first-class MCP tools alongside core tools like `query_collection` — AI agents pick them by name, pass arguments, and receive filtered, formatted results. No PHP is needed; the definition lives in the collection's schema editor.

Use saved-query tools when a particular query is reused or when you want to give an AI agent a stable, named entry point. For ad-hoc filter composition, `query_collection` is the better choice.

## Quick start

Open the schema editor for the collection you want to expose. On the **MCP** tab, find the **Custom MCP Tools** field and enter a JSON array:

```json
[
  {
    "name": "featured_posts",
    "description": "Return featured blog posts, newest first.",
    "filters": {
      "featured": { "value": true }
    },
    "sort":  "date:desc",
    "limit": 10
  }
]
```

Save the schema. The tool `featured_posts` is live on `/mcp` immediately — no rebuild, no restart.

## Tool definition reference

Each entry in the array is an object with the following fields. Only `name` and `description` are required.

| Field | Required | Type | Notes |
|---|---|---|---|
| `name` | yes | string | Snake_case `^[a-z][a-z0-9_]*$`, max 64 characters. Globally unique across all tools. |
| `description` | yes | string | Min 1, max 1024 characters. Describe what the tool returns — not how an agent should use it. |
| `params` | no | object | Typed caller parameters (see [Parameterized tools](#parameterized-tools)). Omit for fixed-filter tools. |
| `filters` | no | object | Field-name → `{value, operator?}` map. Filter values may reference `{{params.X}}` placeholders. |
| `sort` | no | string | REST-style sort spec, e.g. `date:desc` or `priority:asc,date:desc`. |
| `limit` | no | integer | Max results per call. Default 20, capped at 50. |
| `offset` | no | integer | Skip the first N matching results. Default 0. |
| `include` | no | string | REST-style include filter — escape hatch for filter shapes the `filters` object can't express. |
| `exclude` | no | string | REST-style exclude filter — escape hatch. |
| `format` | no | enum | Output format for rich-text fields: `markdown` (default), `html`, or `text`. |

## Fixed-filter tools

Fixed-filter tools take no caller arguments. They are preset queries with a stable result shape — useful for "give me all draft posts" or "return the three most recent announcements":

```json
[
  {
    "name": "draft_posts",
    "description": "Return blog posts pending editorial review, oldest first.",
    "filters": {
      "status": { "value": "draft" }
    },
    "sort":  "date:asc",
    "limit": 20
  },
  {
    "name": "recent_announcements",
    "description": "The three most recent site announcements.",
    "sort":  "date:desc",
    "limit": 3
  }
]
```

## Parameterized tools

Add a `params` block to accept caller-supplied arguments. Reference them in filter values with `{{params.X}}` placeholders.

```json
{
  "name": "find_listings_by_city",
  "description": "Search active real-estate listings by city and optional maximum price.",
  "params": {
    "city": {
      "type": "string",
      "description": "City name (case-insensitive substring match).",
      "required": true
    },
    "max_price": {
      "type": "number",
      "description": "Maximum listing price in USD.",
      "minimum": 0
    }
  },
  "filters": {
    "status": { "value": "active" },
    "city":   { "operator": "contains", "value": "{{params.city}}" },
    "price":  { "operator": "lte",      "value": "{{params.max_price}}" }
  },
  "sort":  "price:asc",
  "limit": 20
}
```

### How placeholders work

- `{{params.X}}` is the only recognized placeholder syntax. Other `{{...}}` strings in filter values are treated as literals.
- When a placeholder replaces the entire filter value (e.g. `"value": "{{params.city}}"`), the resolved value is type-coerced to match the param's declared `type`.
- When a placeholder is embedded in a longer string (e.g. `"value": "category-{{params.slug}}"`), the resolved segment is cast to string and the parts are concatenated.
- If an optional param is not supplied by the caller, the filter containing its placeholder is excluded from the query. The tool still runs against the remaining filters.

### Param properties

| Property | Required | Type | Notes |
|---|---|---|---|
| `type` | yes | enum | `string`, `number`, `integer`, `boolean` |
| `description` | no | string | Shown to AI agents in the generated inputSchema |
| `required` | no | boolean | Default `false`. Required params are enforced by the SDK before dispatch. |
| `default` | no | any | Default value used when the param is absent |
| `enum` | no | array | Restrict to a specific set of values |
| `minimum` | no | number | Numeric lower bound |
| `maximum` | no | number | Numeric upper bound |
| `format` | no | string | JSON Schema format hint (e.g. `date`, `uri`) |

Param names must match `^[a-z][a-z0-9_]*$`.

## Filter operators

Specify an `operator` alongside `value` in any filter entry. The default is `eq`.

| Operator | Meaning |
|---|---|
| `eq` | Equal (default) |
| `ne` | Not equal |
| `lt` | Less than |
| `lte` | Less than or equal |
| `gt` | Greater than |
| `gte` | Greater than or equal |
| `contains` | Substring match (case-insensitive for string fields) |
| `starts` | Starts with |
| `ends` | Ends with |
| `in` | Value is in a pipe-separated list (`a\|b\|c`) |
| `notin` | Value is not in a pipe-separated list |

```json
"filters": {
  "tag":    { "operator": "contains", "value": "featured" },
  "rating": { "operator": "gte",      "value": 4 },
  "status": { "operator": "notin",    "value": "draft|archived" }
}
```

## Persona inheritance and safety

A saved-query tool inherits its access level from the parent collection's **MCP Access** setting (`admin` or `public`). There is no per-tool override.

When the public persona calls the tool, T3's standard safety filters run after the tool's filters: draft objects are excluded, and fields marked `expose: false` in the collection's MCP field settings are stripped from every response. Saved-query tools cannot bypass these filters.

## Tool name collisions

Collision detection runs at two points:

**Save-time (warning, non-blocking).** When you save the schema, each tool name is checked against currently registered core and extension tools. A collision triggers a warning banner in the admin — it does not block the save. The colliding tool will be silently skipped from `tools/list` at runtime until the name is changed.

**Server-build-time (strict deny).** When the MCP server initializes, `SchemaToolRegistrar` walks all collections. If the same tool name appears in two different collections, both tools are dropped from `tools/list`. Core and extension tools always win over schema tools. Rename one of the conflicting tools to resolve the issue.

If a tool is missing from `tools/list` despite being defined in the schema, check the admin's warnings UI for collision notices and review the application log for entries from `SchemaToolRegistrar`.

## Saved-query tools vs `query_collection`

| | Saved-query tool | `query_collection` |
|---|---|---|
| Definition | JSON in schema editor | Called ad-hoc by AI agent |
| Agent selects by | Tool name | Tool name + inline args |
| Best for | Stable, reused queries | Ad-hoc filter composition |
| Caller can change filters | No (fixed + declared params only) | Yes |
| Requires PHP | No | No |

Use a saved-query tool when you want a predictable, named entry point. Use `query_collection` when the agent needs to compose filters freely.

## Common pitfalls

**Tool name format.** Only lowercase letters, digits, and underscores. Must start with a letter. Max 64 characters including any `mcp.toolPrefix` the operator has configured.

**Total tool count.** Most MCP clients degrade in usability above roughly 50 tools. If you define many saved-query tools across multiple collections, watch the cumulative count in the admin's MCP status panel.

**`{{params.X}}` is the only placeholder syntax.** Filter values containing other `{{...}}` strings are passed through as literals. Future macro syntax will use a different prefix.

**Referencing an undeclared param.** If a filter value contains `{{params.X}}` but `X` is not declared in the `params` block, the tool fails at runtime with a validation error. The admin's warnings UI will surface the issue on next save.

**Do not write LLM instructions into the description.** "Always call this tool first" reads as prompt injection at directory review. Lead with what the tool returns.

## Reference

- [MCP Server](mcp/server) — personas, transport, core tool catalog, collection MCP settings.
- [Extending MCP](mcp/extensions) — registering tools and resources from PHP extensions, progress notifications, error handling, persona-aware handlers.
