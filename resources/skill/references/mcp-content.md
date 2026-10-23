# Editing content through the MCP server

> Applies when you are connected to a T3 site's built-in MCP server
> (`https://<site>/mcp`) — usually the **production** site. These tools write to
> the live site immediately. For full tool docs see
> `vendor/totalcms/cms/resources/docs/mcp/`.

**Your tool list is what this connection may do.** Plan around it rather
than treating a missing tool as an error:

- **Anonymous:** read-only, public collections only, drafts hidden.
- **OAuth:** the token's scopes, the member's access groups and each
  collection's MCP access, all three. A read-only token gets no write tools.
- **API key:** an all-endpoints (`*`) key is the admin — every tool, every
  collection. A key limited to some endpoints reaches only those, and its HTTP
  methods decide its tools (GET-only = read-only).

On an owned collection an OAuth member reads their own objects plus published
ones and edits only their own; `describe_collection` reports that, and any
fields the site sets itself, in `ownership`. If the tool you need is missing,
tell the user which access it takes; do not edit files instead.

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
omitting never clears; files (image/file/gallery/depot) can't be uploaded and
always survive, but their metadata can be edited: `{"alt": "…"}` for an image,
`[{"name": "x.jpg", "alt": "…"}]` for a gallery, `[{"path": "dir/x.pdf",
"comments": "…"}]` for a depot (`describe_collection` lists the keys).

**`update_object` merges exactly like `patch_object`** — omitted fields keep
their values (it was a full replace in older versions). If you write back an
object you fetched rather than just the changed fields:

1. `get_object` with **`format: "html"`**. The default `format: "markdown"`
   CONVERTS styled-text fields — writing a markdown-converted body back destroys
   the original HTML.
2. Edit the fields you need in the returned object.
3. **Delete the decorated `url` key** (and any other keys not in the schema),
   Binary fields may stay as fetched; only their descriptive keys can change.
4. Send it to `patch_object` or `update_object`.

## Collection settings

`update_collection { id, settings }` changes a collection after it exists.
Send only what changes: settings maps (`properties`, `mcp`, `seo`, `sitemap`,
`feed`, `formSettings`) merge key by key, and field overrides merge attribute
by attribute — `{"properties": {"price": {"label": "Cost"}}}` relabels one
field and keeps its other overrides; `null` removes one. The override's
`field` type is filled in from the schema. `delete_collection` removes the
settings only — objects stay, and `create_collection` with the same id (pass
the returned settings as `extra`) brings them back. Confirm before deleting.
On the command line, `tcms collection:update <id> --set key=value` does the
same merge.

**`delete_object`** deletes one object (`collection` + `id`). Confirm with the
user first. The record goes to the backup history (restorable with
`tcms backup:restore`), but its uploaded files are deleted for good; the
result lists the fields that held files. It needs the `delete` grant, or an
API key with the DELETE method.

After any write, the response echoes the saved object — verify your change is
in it. Page caches invalidate automatically via the `object.updated` event —
do not call `clear_cache` for content edits.

`create_object` takes the complete-body shape. For hand-authored data the
same schema gotchas apply as local JSON: decks are dictionaries keyed by item
`id` (underscores only), and every object needs its `id`.

## Data Views

`list_views`, `describe_view`, `get_view` and `query_view` read saved
cross-collection queries. They need the Data Views edition, and an OAuth
member also needs the `dataviews` permission unless the view is public. A
view is built with full access, so it can hold drafts and other members'
objects: do not widen a view's `mcp.access` without checking what it returns.

## Site-specific tools and prompts

Sites can define **saved-query tools** (per-collection MCP card) — first-class
tools like `latest_release` or `find_comparison` that wrap a canned query. They
appear in your tool list alongside the built-ins; prefer them when one matches,
they encode the site owner's intent. Sites can also ship **prompts** (editorial
guidance like brand voice); check the prompt list before writing content.
