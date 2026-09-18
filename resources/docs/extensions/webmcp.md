---
title: "WebMCP (Bundled Extension)"
description: "Make your forms and public collections callable by a browser AI agent inside the visitor's own session, through the WebMCP origin trial in Chrome 149+. Experimental."
since: "3.6.0"
---

# WebMCP

`totalcms/webmcp` — bundled with Total CMS, off by default, **experimental**. Renders forms an AI agent in the visitor's browser can fill and submit, and registers search and get tools for the collections you choose, so an agent on the page can look content up. One Twig call; no API keys, no separate login, because the agent works inside the session the visitor already has.

## What WebMCP is

[WebMCP](https://github.com/webmachinelearning/webmcp) is a W3C Web Machine Learning Community Group proposal from Google and Microsoft. A page hands tool definitions to a browser-resident agent instead of the agent scraping the DOM. Two halves: an **imperative** JavaScript API (`document.modelContext.registerTool()`) and a **declarative** one, four HTML attributes on a plain form. Chrome documents both under [Built-in AI → WebMCP](https://developer.chrome.com/docs/ai/webmcp).

## Status

Chrome 149 opened the WebMCP **origin trial**; Edge follows Chromium; no other engine has taken a position. The imperative API is specified; the declarative half is still an explainer and its attribute names may change. This extension tracks the spec: a rename is a version bump here, never a CMS release. Nothing it emits affects a browser without the API.

## Enabling

1. **Admin → Extensions → WebMCP → Enable** (or `tcms extension:enable totalcms/webmcp`).
2. To use it without a flag, register your site's origin for the trial at [developer.chrome.com/origintrials](https://developer.chrome.com/origintrials/#/register_trial/4163014905550602241) and paste the token into **Chrome origin-trial token**. The extension emits it as a `<meta http-equiv="origin-trial">` on every page. For local testing, `chrome://flags/#enable-webmcp-testing` does the same job.
3. Under **Collections exposed as read tools**, pick the collections an agent may search. A visitor's agent only sees those that allow public `read` under the collection's **Public Operations**, because the API would refuse the call anyway; your own agent, while you are signed in, sees them all.

The settings page also has **Agent-callable forms** and **Read tools** switches. Off, the templates below keep rendering plain forms and nothing is registered.

## Agent-callable forms

```twig
{{ webmcp_form('contact', {
	name: 'send_message',
	description: 'Send a message to the site owner',
}) }}
```

This is `cms.form.builder('contact')` with the WebMCP attributes on the form tag and on every control:

| Option | Default | What it does |
|---|---|---|
| `name` | `create_{collection}`, or `update_{collection}` with an `id` | The tool name the agent calls. Lower-cased, `a–z 0–9 _`, at most 64 characters |
| `description` | "Create a new {singular label} in the {collection name} collection" | What the tool does, in the agent's words. Markup stripped, 200 characters |
| `params` | the schema's `help`, else `label`, per property | Per-property descriptions, e.g. `{params: {mood: 'One word for how you feel'}}` |
| `autosubmit` | `false` | Let the agent submit without the visitor confirming. See Security |

Every other option passes through to `cms.form.builder()`, so `id`, `class`, `useFormGrid` and the rest work as they do there. The browser synthesizes the tool's input schema from the form itself: each control's `name` is a property, `required`, `type`, `min`, `max`, `step` and `pattern` become constraints, a `<select>` becomes an enum. The descriptions the extension adds are what the agent reads when it decides what to put where.

When an agent submits, the bundled script answers with the form's own save result: `{ok: true, id: "…", message: "Saved"}`, or `{ok: false, error: "…"}` when validation or the API refused it. The visitor sees the same status banner a human submit shows. While an agent is driving a form it carries a `webmcp-active` class, so you can style it.

## Read tools

Two tools are registered when the page loads, the same shape as the MCP server's `query_collection` and `get_object`:

| Tool | Input | Returns |
|---|---|---|
| `search_content` | `collection`, `q` (search terms), `limit` (up to **Search results per call**) | id, title, summary or description, and url of each match |
| `get_content` | `collection`, `id` | the object as JSON |

`collection` is an enum of the collections the manifest offers, each named in the tool description with its plural label and its description, which you wrote. The tools read the collections API with the browser's own session, so what the enum holds depends on who is looking: a visitor gets the listed collections that allow public `read`; a signed-in operator gets every listed collection, on a public page as much as in the dashboard. Both tools are annotated `readOnlyHint` and `untrustedContentHint`, so a well-behaved agent treats what comes back as data, not instructions.

## In the admin dashboard

**Read tools in the admin dashboard**, off by default, loads the script on dashboard pages too, so an agent in the operator's own browser gets the same two tools there. Admin forms are not annotated: an agent in the dashboard would be the operator, with none of the scopes, group access or audit trail the [MCP server](docs/mcp/server) gives an agent, so for agents that manage content, that server is the right surface.

## Testing

- Chrome 150+ with `chrome://flags/#enable-webmcp-testing` enabled, or the origin-trial token in place.
- The [Model Context Tool Inspector](https://github.com/beaufortfrancois/model-context-tool-inspector) Chrome extension lists a page's tools, calls them by hand, and can drive them through Gemini.
- DevTools console: the script logs under `[webmcp]` how many tools it registered, and every agent submit.
- `GET /api/ext/totalcms/webmcp/tools.json` is the manifest the script reads; it shows which collections made the list for the session asking.

## Security

An agent inside your visitor's session is a **confused deputy**: it carries the visitor's login and can submit any annotated form as them. CSRF protection does not help here, because the agent is not cross-origin; it reads the real token out of the page. Four rules hold, and the extension enforces the ones it can:

1. **Tool descriptions come from schema metadata and your own words only.** Field help and labels, the collection's name and description, the options you pass. Never object data, never anything a visitor typed. The spec names poisoned tool metadata as the primary attack on agents, and a CMS that interpolated content into a description would ship that attack into every form.
2. **Autosubmit is opt-in per form.** It removes the human from the loop. Keep it off for anything that changes data you care about, and never use it on a destructive form.
3. **Registration forms are never annotated.** `webmcp_form()` refuses `register: true`. Public registration signs the new user in; an agent-callable, session-minting form is the worst combination available. The same goes for login and delete forms: use `cms.form.builder()` for those.
4. **Server-side rules are the boundary.** Validation, public operations and access groups apply to an agent's submit exactly as to a human's. Annotating a form adds no new server-side surface; it only makes an existing form easier to find.

## Limitations

- Chrome and Chromium-based browsers only, behind the origin trial or a flag. No Safari or Firefox.
- The declarative attributes may be renamed by the spec; this extension will follow, and the change will be an extension update.
- Read tools cover collections with public read; there are no write tools beyond the forms you annotate, by design.
