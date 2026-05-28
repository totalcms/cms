---
title: "MCP Prompts"
description: "Define Twig-rendered AI prompt templates in the admin and expose them via the MCP server's prompts/list and prompts/get endpoints."
audience: intermediate
updated: 2026-05-26
related:
  - mcp/server
  - mcp/saved-query-tools
  - mcp/extensions
---

# MCP Prompts

MCP prompts are reusable, operator-authored templates that AI clients discover via `prompts/list` and invoke via `prompts/get`. Each prompt lives as an object in the `mcp-prompt` reserved collection. The body is a Twig template — the full `cms.*` function library is available alongside declared argument values.

Use prompts when you want to ship a starting workflow to an AI agent — a house-style blog scaffold, a brand-voice rewrite template, or a site-wide content audit outline. For data retrieval, use [Saved-Query Tools](mcp/saved-query-tools) instead.

| | Prompts | Saved-Query Tools |
|---|---|---|
| Returns | Rendered text (one user-role message) | Filtered collection data |
| AI agent uses it as | A starting point or instruction | A data query |
| Defined in | `mcp-prompt` collection | Collection schema MCP tab |
| Twig in body | Yes — full `cms.*` available | No |

## Quick start

1. In the admin, open **MCP → Prompts** and click **Add Prompt**.
2. Fill in **Name** (snake_case, e.g. `draft_blog_post`), **Description**, and **Body**.
3. Save. The prompt appears in `prompts/list` immediately — no rebuild, no restart.

```
Name:        draft_blog_post
Description: Draft a new blog post in our house style.
Body:        Write a {{ args.tone | default('professional') }} blog post titled "{{ args.title }}".
             Use H2 subheadings, keep paragraphs under 5 sentences,
             and end with a call to action.
Args:        title (required), tone (optional)
```

An AI client invokes it as:

```json
{
  "method": "prompts/get",
  "params": {
    "name": "draft_blog_post",
    "arguments": { "title": "10 Tips for Summer Photography", "tone": "conversational" }
  }
}
```

The server renders the Twig body with the supplied arguments and returns one `user`-role message.

## Worked examples

### House-style scaffold

Return a structured writing brief that matches editorial standards:

```twig
You are writing for {{ cms.config('displayName') }}.

Write a {{ args.format | default('long-form') }} article titled: {{ args.title }}

House rules:
- Sentences: ≤ 25 words.
- Tone: {{ args.tone | default('professional') }}.
- Always include a tldr summary in the opening paragraph.
- End with a bulleted "Key Takeaways" section.

Begin the article now.
```

**Args:** `title` (required), `format` (optional), `tone` (optional).

### Object-aware workflow

Pull live collection data into the prompt body using `cms.object()`:

```twig
{% set post = cms.object(args.post_id, 'blog') %}
You are editing an existing blog post.

Title:     {{ post.title }}
Published: {{ post.date }}
Current body:
{{ post.body | striptags }}

Rewrite the body in a {{ args.tone | default('professional') }} tone.
Keep the same structure. Return only the rewritten body, no title.
```

**Args:** `post_id` (required), `tone` (optional).

The agent passes the post's ID; the prompt fetches the live record and injects it. Editors can trigger a rewrite by invoking `prompts/get` from Claude Desktop, Cursor, or any MCP client.

### Site-wide aggregation

Give the agent a cross-collection overview to answer high-level questions:

```twig
Site: {{ cms.config('displayName') }}
Date: {{ "now" | date("Y-m-d") }}

Content summary:
{% for collection in cms.collections %}
- {{ collection.labelPlural }}: {{ collection.count }} objects
{% endfor %}

Your task: {{ args.task }}

Focus on any content gaps, outdated objects (older than {{ args.months | default(6) }} months),
or collections with fewer than 5 objects.
```

**Args:** `task` (required), `months` (optional).

## Twig context reference

The body template receives these variables at render time:

| Variable | Type | Notes |
|---|---|---|
| `args.*` | string (or typed if declared) | Caller-supplied argument values. Only declared args are injected — undeclared keys are silently dropped. |
| `cms.*` | object | Full T3 Twig adapter: `cms.object()`, `cms.collection()`, `cms.config()`, `cms.collections`, `cms.env`, etc. |
| Twig builtins | — | All Twig filters and functions: `date`, `default`, `striptags`, `upper`, `for`, `if`, etc. |

Arguments with `required: true` are validated before rendering. If a required argument is absent, the server returns a JSON-RPC error — the body template is never called.

## Access model

Each prompt has an **Access** setting:

| Setting | Who can call the prompt |
|---|---|
| `(inherit from collection)` | Inherits the target collection's MCP access. Blank `targetCollection` defaults to `admin`. |
| `Admin only` | API-key authenticated callers only. |
| `Authenticated` | OAuth Bearer token callers (and admin). |
| `Public` | Anonymous AI agents (and all above). |

Persona enforcement runs at two points:

- **`prompts/list`** — inaccessible prompts are filtered out entirely. A public caller never sees an `admin` prompt in the list.
- **`prompts/get`** — the handler re-checks access at call time. A caller who guesses an admin prompt name receives a JSON-RPC error, not the rendered content.

## Argument declaration

Arguments declared in the **Arguments** card field are typed and validated:

| Field | Notes |
|---|---|
| **Name** | Snake_case (`^[a-z][a-z0-9_]*$`). Referenced in the body as `{{ args.name }}`. |
| **Description** | Shown to AI agents in the `prompts/list` response so they know what to pass. |
| **Required** | Toggle. Missing required args return a JSON-RPC error before the body renders. |

Optional arguments with no default in the body render as an empty string when absent. Use Twig's `default` filter: `{{ args.tone | default('professional') }}`.

Only declared argument names are injected into the template context. Extra keys passed by the caller are silently dropped.

## Code-defined prompts (extensions)

Extensions can ship code-defined prompts alongside the collection-stored ones this page covers. They share the same `prompts/list` and `prompts/get` surface; the difference is authoring path — PHP in an extension vs. JSON object in the admin.

See **[Extending MCP → Registering code-defined prompts](mcp/extensions)** for the full guide: signature, when-to-use comparison vs. collection-stored, worked examples, access tiers, and collision policy.

## ID field and snake_case

The `id` field on the `mcp-prompt` schema is readonly and auto-generated from the **Name** you enter. It follows the `snakeCase` ID setting — see [ID Field](fields/id) for details. The `name` field is the canonical identifier AI clients use to call the prompt; keep it stable once published.

## Limitations (v1)

- **Single user-role message.** Each prompt returns one `user`-role `PromptMessage`. Multi-turn scaffolds (system + user) are a v2 target.
- **Synchronous render.** The body is rendered inline during `prompts/get`. Long-running `cms.*` calls (e.g. fetching many objects) block the response. Keep the body lightweight.
- **No per-prompt persona override.** Access is set at the prompt level. Collection inheritance is coarse-grained — there is no field-level or argument-level access control.
- **No listChanged push in v1.** Clients that have called `prompts/list` are not notified when a new prompt is added until they re-list (e.g. on reconnect).

## Reference

- [MCP Server](mcp/server) — personas, transport, core tool catalog, collection MCP settings.
- [Saved-Query Tools](mcp/saved-query-tools) — parameterised data queries; the right tool when you need filtered collection content rather than a workflow template.
- [Extending MCP](mcp/extensions) — `registerMcpPrompt()` in the full context of the extension MCP API surface.
