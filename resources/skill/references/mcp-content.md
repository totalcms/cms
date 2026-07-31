# Editing content through the MCP server

> Applies when you are connected to a T3 site's built-in MCP server
> (`https://<site>/mcp`) — usually the **production** site. These tools write to
> the live site immediately. For full tool docs see
> `vendor/totalcms/cms/resources/docs/mcp/`.

Three access levels: anonymous (public read-only, drafts hidden), OAuth
(scoped by grants), and `X-API-Key` header (admin — write tools, schema tools,
all collections). If a write tool is missing from your tool list, you are not
connected as admin.

## Discovery, then read

```
list_collections                                  # what exists + object counts
describe_collection { collection: "blog" }        # per-property detail; indexed flags
query_collection    { collection: "blog", include: "draft:false", sort: "date:desc" }
search_collection   { collection: "blog", query: "\"exact phrase\" or keyword" }
get_object          { collection: "blog", id: "my-post" }
```

Rules that prevent wasted calls:

- `query_collection` filters **indexed** fields only; `describe_collection` shows
  which. Non-indexed fields exist on objects but only `get_object` returns them.
- `query_collection` does not free-text search; `search_collection` does not
  filter by field value. Pick the right one.
- Results are decorated with a `url` field per item. It is presentation-only —
  it is NOT part of the object.

## Writing objects

**Default to `patch_object`** — it merges only the fields you send; omitted
fields keep their current values, so there is no round-trip and nothing to
lose. Rules: containers (card/deck/list) replace WHOLE (send the complete
container to change or remove any part); clearing is explicit (`""` / `[]`);
omitting never clears; binary fields (image/file/gallery/depot) can't be
written and always survive.

**`update_object` is a FULL REPLACE** — any field absent from `data` reverts
to its schema default. Only use it to rewrite a whole record, and never write
back an object you have not fully fetched:

1. `get_object` with **`format: "html"`**. The default `format: "markdown"`
   CONVERTS styled-text fields — writing a markdown-converted body back destroys
   the original HTML.
2. Edit the fields you need in the full returned object.
3. **Delete the decorated `url` key** (and any other keys not in the schema).
4. `update_object` with the complete body.

After any write, the response echoes the saved object — verify your change is
in it. Page caches invalidate automatically via the `object.updated` event —
do not call `clear_cache` for content edits.

`create_object` takes the complete-body shape. For hand-authored data the
same schema gotchas apply as local JSON: decks are dictionaries keyed by item
`id` (underscores only), and every object needs its `id`.

## Site-specific tools and prompts

Sites can define **saved-query tools** (per-collection MCP card) — first-class
tools like `latest_release` or `find_comparison` that wrap a canned query. They
appear in your tool list alongside the built-ins; prefer them when one matches,
they encode the site owner's intent. Sites can also ship **prompts** (editorial
guidance like brand voice); check the prompt list before writing content.
