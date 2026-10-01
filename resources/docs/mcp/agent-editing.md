---
title: "Editing Content with AI Agents"
description: "The safe workflow for reading and writing site content through the MCP server: tool selection, the full-replace round-trip, and verification."
audience: intermediate
updated: 2026-09-09
related:
  - mcp/server
  - mcp/saved-query-tools
  - mcp/prompts
---

# Editing Content with AI Agents

The MCP server lets an AI agent (Claude Code, Cursor, claude.ai, …) read and
write your site's content directly. This page is the workflow guide: which tool
to reach for, and the one rule that keeps writes safe. For setup and the full
tool catalog, see [MCP Server](docs/mcp/server).

Writes require the **admin persona** (an `X-API-Key` header) or an OAuth token
with write access. If `update_object` is missing from the agent's tool list,
it is connected read-only.

## Picking the right read tool

Three read tools, three jobs:

| You want… | Use | Not |
|---|---|---|
| items matching field values, sorted, paginated | `query_collection` with `include`/`exclude`/`sort` | `search_collection` |
| free-text matches inside a collection | `search_collection` | `query_collection` |
| one complete record, including non-indexed fields | `get_object` | either of the above |

`describe_collection` shows which properties are indexed — only those appear in
query/search results and only those filter or sort. Everything else exists on
the object and comes back from `get_object` alone.

If the site defines [saved-query tools](docs/mcp/saved-query-tools), prefer
them when one matches the task — they encode the site owner's intended query.

## Writing: patch by default

**`patch_object` is the safe default for edits.** It merges only the fields
you send over the stored object — omitted fields keep their current values, so
there is nothing to round-trip and nothing to accidentally lose:

```
patch_object { collection: "blog", id: "summer-update", data: { title: "New Title" } }
```

Three merge rules to know:

- **Containers replace whole.** A card, deck, or list value in the payload
  replaces the stored one entirely — send the complete container to change any
  part of it (this is also how you *remove* a deck item).
- **Clearing is explicit.** Pass the empty value (`""` for text, `[]` for
  containers) to clear a field. Omitting a field never clears it.
- **Binary fields are untouchable.** Image, file, gallery, and depot fields
  always keep their current values; a payload that sets one is refused.

## update_object

**`update_object` merges exactly like `patch_object`.** Fields in `data` are
written and omitted fields keep their current values, so a missing field is
never lost. It used to replace the whole object, and any field an agent left
out reverted to its default. Clearing still has to be explicit: send the
field's empty value.

If you write back an object you fetched, rather than only the changed fields:

1. **Fetch it with `format: "html"`.**
   The default `format` is `markdown`, which *converts* styled-text fields for
   reading. Markdown-converted content written back would permanently replace
   the original HTML. `html` returns fields as stored.
2. **Strip the `url` key.** Read tools decorate each item with its public
   `url`; it is not part of the object and must not be written back.
3. **Strip binary fields** (image, file, gallery, depot). A payload that sets
   one is refused; omitted, they keep their values.
4. **Send it** to `update_object` or `patch_object`. The response echoes the
   saved object — confirm your change is in it.

After any write — patch, update, or create — **do not clear the cache**:
object writes fire the `object.updated` event, which invalidates affected page
caches automatically.

`create_object` takes the same complete-body shape. Hand-authored data follows
the same rules as any imported JSON: every object carries its `id`, and deck
fields are dictionaries keyed by item id (letters, numbers, and underscores
only).

## Writing for agents

Five text slots in a site's configuration are read by AI agents rather than
by people. They are the agent's only documentation for the content model,
and generic text in them produces generic results: an agent told a property
is "The title" will write a title, and nothing more specific.

| Slot | Where | Who reads it |
|---|---|---|
| Schema `description` | top level of the schema JSON | `list_schemas`, `get_schema`; `schema:lint` warns when empty |
| Property `help` | each property | the form, **and** the MCP catalog when `mcp.description` is empty |
| Property `mcp.description` and `mcp.expose` | each property's MCP Details | `describe_collection` and every tool description |
| Collection `mcp` card: `access`, `description`, `resource` | Collections → Settings → MCP Server | `list_collections` and the tool catalog |
| Saved-query tool `description` | Collections → Settings → MCP Server | the tool list an agent chooses from |

The resolution order for a property is `mcp.description`, then `help`, then
`label`. Most sites need only good `help`; reach for `mcp.description` when
the editor-facing hint and the agent-facing one should differ.

Do not confuse these with the other descriptions a collection carries. The
collection's general **Description** is for the admin dashboard. The SEO
mapping's **Description Template** composes the meta description crawlers
read out of the object's properties. A Site Builder page's own `description` is
its meta description. None of those reach an agent's tool catalog.

### What good looks like

Write each slot as if briefing a new writer who cannot see the site. Say
what the value is for, what shape it takes, and what a good one looks like.
State constraints an agent cannot infer from the field type.

| Slot | Generic | Useful |
|---|---|---|
| schema `description` | "Blog posts." | "Long-form articles for the company blog. One post per object. Posts are listed newest first on /blog and each has its own page at /blog/{id}." |
| property `help` on `title` | "The title." | "Headline shown in listings and as the page title. Sentence case, under 70 characters, no trailing period." |
| property `help` on `summary` | "A summary." | "One or two plain sentences used on listing cards and as the meta description. Under 160 characters. No markup." |
| property `help` on `categories` | "Categories." | "Editorial sections this post belongs to, usually one. Pick from the existing values; add a new one only for a genuinely new topic." |
| property `help` on `publish` | "Publish date." | "The date the post goes live. Future dates schedule it; the listing hides it until then." |
| collection `mcp.description` | "The blog." | "Published company blog posts. Drafts are hidden from anonymous callers. Filter by `categories` or `author`; sort by `publish`." |
| saved-query tool | "Gets posts." | "Returns the five most recent published posts, newest first, with title, summary and url." |

A description written this way does double duty: the same text renders
under the field in the admin form and guides an editor.

### The acceptance test

`tcms schema:lint <id>` reports every property with empty help and every
schema without a description. Run it before calling schema work finished,
and run `schema:lint --strict` to have it fail on warnings. An agent that
writes schemas should treat a clean strict lint as its definition of done,
not a follow-up.

## Guiding the agent

Beyond the description slots above, one more site-side feature shapes what
agents produce: **[Prompts](docs/mcp/prompts)** deliver editorial guidance
(brand voice, per-collection editing instructions) straight into the agent's
client. A `brand_voice` prompt is the cheapest way to keep agent-written
copy on-message.

## A typical editorial session

```
get_site_info                                     # right site, right edition?
query_collection { collection: "blog", sort: "date:desc", limit: 5 }
get_object       { collection: "blog", id: "summer-update", format: "html" }
# …rework the body…
patch_object     { collection: "blog", id: "summer-update", data: { body: "<p>…</p>" } }
# response echoes the merged object; the live page updates on next request
```
