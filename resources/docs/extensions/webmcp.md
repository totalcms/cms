---
title: "WebMCP (Bundled Extension)"
description: "Make your forms callable by a browser AI agent, and hand it the MCP server's read tools inside the visitor's own session, through the WebMCP origin trial in Chrome 149+. Experimental."
since: "3.6.0"
---

# WebMCP

`totalcms/webmcp` — bundled with Total CMS, off by default, **experimental**. Renders forms an AI agent in the visitor's browser can fill and submit, and hands that agent the **MCP server's read tools**, called inside the session the visitor already has. One Twig call for forms; nothing to configure for reads beyond what the MCP server already knows. Read tools need the Standard edition or above, because they are the MCP server's — Data Views tools (`list_views`/`query_view`) stay Pro and simply come back empty below it.

## What WebMCP is

[WebMCP](https://github.com/webmachinelearning/webmcp) is a W3C Web Machine Learning Community Group proposal from Google and Microsoft. A page hands tool definitions to a browser-resident agent instead of the agent scraping the DOM. Two halves: an **imperative** JavaScript API (`document.modelContext.registerTool()`) and a **declarative** one, four HTML attributes on a plain form. Chrome documents both under [Built-in AI → WebMCP](https://developer.chrome.com/docs/ai/webmcp).

## Status

Chrome 149 opened the WebMCP **origin trial**; Edge follows Chromium; no other engine has taken a position. The imperative API is specified; the declarative half is still an explainer and its attribute names may change. This extension tracks the spec: a rename is a version bump here, never a CMS release. Nothing it emits affects a browser without the API.

## Enabling

1. **Admin → Extensions → WebMCP → Enable** (or `tcms extension:enable totalcms/webmcp`).
2. To use it without a flag, register your site's origin for the trial at [developer.chrome.com/origintrials](https://developer.chrome.com/origintrials/#/register_trial/4163014905550602241) and paste the token into **Chrome origin-trial token**. The extension emits it as a `<meta http-equiv="origin-trial">` on every page. For local testing, `chrome://flags/#enable-webmcp-testing` does the same job.
3. There is no list of collections to expose here. What an agent may read is what the MCP server would let that person read: each collection's **MCP Access** on its MCP tab — Public collections are readable by anyone; Authenticated collections are readable by a signed-in, non-administrator only when their access groups grant them read; Admin only collections are never reachable by anyone but an administrator — plus the per-property **Expose to MCP** flag, and the `mcp.enabled` / `mcp.publicAccess` switches. See [MCP Server](docs/mcp/server).

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
| `params` | title from the schema's `label`, description from its `help` | Per-property overrides: a string replaces the description, `{title: …, description: …}` replaces either, e.g. `{params: {mood: 'One word for how you feel'}}` |
| `autosubmit` | `false` | Let the agent submit without the visitor confirming. See Security |

Every other option passes through to `cms.form.builder()`, so `id`, `class`, `useFormGrid` and the rest work as they do there. The browser synthesizes the tool's input schema from the form itself: each control's `name` is a property, `required`, `type`, `min`, `max`, `step` and `pattern` become constraints, a `<select>` becomes an enum. On top of that the extension puts `toolparamtitle` and `toolparamdescription` on every control — the field's label and its help text, the same two strings a person filling the form reads. They are what the agent reads when it decides what to put where, so a schema whose fields carry real help text produces a better tool than one whose fields are bare. A field with no help gets a title and no description rather than its label twice.

When an agent submits, the bundled script answers with the form's own save result: `{ok: true, id: "…", message: "Saved"}`, or `{ok: false, error: "…"}` when validation or the API refused it. The visitor sees the same status banner a human submit shows. While an agent is driving a form it carries a `webmcp-active` class, so you can style it.

## Read tools

When the page loads in a browser that has the WebMCP API, `assets/webmcp.js` makes one `tools/list` call to this site's `/mcp` endpoint and registers only the tools the server marks read-only, under the server's own name, description and input schema: `list_collections`, `describe_collection`, `query_collection`, `get_object`, `search_collection`, `search_collections`, `list_views` and `query_view`, `get_site_info`, every [saved-query tool](docs/mcp/saved-query-tools) you have defined, and any read-only tool an extension adds. `assets/webmcp.js` imports `assets/bridge.js` — the script that answers an agent's form submits — so a page that needs read tools gets both from one script tag; a page with **Read tools** off and **Agent-callable forms** on loads `assets/bridge.js` alone. A browser without the API costs the server nothing: the script checks for `document.modelContext` before it makes a request.

The call carries the browser's own session, and the MCP server treats a same-origin session like an OAuth client acting for that user: a visitor stays the same anonymous client it always was, seeing collections whose MCP Access is Public (with `mcp.publicAccess` on); a signed-in user who is not an administrator sees collections whose MCP Access is Authenticated or Public **and** that their access groups grant read on — a collection whose MCP Access is Admin only is never readable by a non-administrator, grant or not; an administrator sees everything. The script registers only tools the server marks read-only, so no write tool ever reaches an agent on a page; for a signed-in session the server itself lists none. Results pass through the same shaping as any MCP client's: properties not exposed to MCP are stripped, styled text is rendered, drafts are hidden from anyone without draft authority.

Every registered tool is annotated `readOnlyHint` and `untrustedContentHint`, so a well-behaved agent treats what comes back as data, not instructions.

### Upgrading from the first release

The first release of this extension had its own two tools, `search_content` and `get_content`, over the REST collections API, with its own list of exposed collections. Those are gone. What changes for an operator:

- **Which collections an agent sees** now follows each collection's MCP Access, not its Public Operations or the old list. A collection with public REST read but "Admin only" MCP access disappears from a visitor's agent; the reverse appears.
- **Which fields come back** follows the per-property Expose to MCP flag.
- **Signed-in users** who are not administrators now see collections whose MCP Access is Authenticated or Public and that their access groups grant read on — never a collection marked Admin only — where before anyone signed into the operator collection saw every listed collection.
- **The edition, `mcp.enabled` and `mcp.publicAccess`** now apply to the browser too. On a Lite site the read tools stop working; Standard and above have them — Data Views tools (`list_views`/`query_view`) are Pro and simply come back empty below it. Forms still work everywhere.
- The **Collections exposed as read tools** and **Search results per call** settings are removed; a saved value is ignored.

## In the admin dashboard

**Read tools in the admin dashboard**, off by default, loads the script on dashboard pages too, so an agent in the operator's own browser gets the same tools there — read-only, even for an administrator. Admin forms are not annotated. For an agent that manages content, the [MCP server](docs/mcp/server) with an API key or OAuth is the right surface: scopes, group access and an activity log.

## Testing

- Chrome 150+ with `chrome://flags/#enable-webmcp-testing` enabled, or the origin-trial token in place.
- The [Model Context Tool Inspector](https://github.com/beaufortfrancois/model-context-tool-inspector) Chrome extension lists a page's tools, calls them by hand, and can drive them through Gemini.
- DevTools console: the script logs under `[webmcp]` how many tools it registered, and every agent submit.
- The Network tab shows one POST to `/mcp` per page load, `Mcp-Method: tools/list`, and one per call. A 403 means the edition does not include MCP; a 404 means `mcp.enabled` is off; a 401 for a signed-out visitor means `mcp.publicAccess` is off.

## Security

An agent inside your visitor's session is a **confused deputy**: it carries the visitor's login and can submit any annotated form as them. CSRF protection does not help here, because the agent is not cross-origin; it reads the real token out of the page. Four rules hold, and the extension enforces the ones it can:

1. **Tool descriptions come from schema metadata and your own words only.** Field help and labels, the collection's name and description, the options you pass. Never object data, never anything a visitor typed. The spec names poisoned tool metadata as the primary attack on agents, and a CMS that interpolated content into a description would ship that attack into every form.
2. **Autosubmit is opt-in per form.** It removes the human from the loop. Keep it off for anything that changes data you care about, and never use it on a destructive form.
3. **Registration forms are never annotated.** `webmcp_form()` refuses `register: true`. Public registration signs the new user in; an agent-callable, session-minting form is the worst combination available. The same goes for login and delete forms: use `cms.form.builder()` for those.
4. **Server-side rules are the boundary.** Validation, public operations and access groups apply to an agent's submit exactly as to a human's. Annotating a form adds no new server-side surface; it only makes an existing form easier to find.

## Limitations

- Chrome and Chromium-based browsers only, behind the origin trial or a flag. No Safari or Firefox.
- The declarative attributes may be renamed by the spec; this extension will follow, and the change will be an extension update.
- Read tools are the MCP server's, so they need the Standard edition or above and `mcp.enabled`; there are no write tools beyond the forms you annotate, by design.
