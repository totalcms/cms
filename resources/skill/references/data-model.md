# Data model reference: collections, schemas, objects

> This is a summary. For exhaustive detail (every field type and option), read the
> on-disk docs that ship with the package at
> `vendor/totalcms/cms/resources/docs/{collections,schemas,fields}/` (or query the
> MCP server if connected).

Three concepts:
- **Schema** — the shape: which fields an object has and their types. JSON under
  `tcms-data` / managed via `schema:*`. 24 reserved schemas (blog, image, gallery,
  builder-page, …) plus your own custom schemas.
- **Collection** — a named bucket of objects bound to a schema (e.g. `blog` uses the
  `blog` schema). Managed via `collection:*`.
- **Object** — one record (one JSON file) in a collection.

## Inspect

```bash
vendor/bin/tcms schema:list --json
vendor/bin/tcms collection:list --json
vendor/bin/tcms collection:get blog --json
vendor/bin/tcms object:list blog --json
vendor/bin/tcms object:get blog my-post --json
```

## Create a schema

Author a schema JSON file, then import it:

```bash
vendor/bin/tcms schema:import my-schema.json
```

Field types and their options are documented at
`vendor/totalcms/cms/resources/docs/fields/` (20+ field types). Look up exact
`type` + `field` keys there rather than guessing — or use MCP `docs_field_type`.

## Create / import content

There is **no `object:create` command.** Create objects via:
- the **admin UI**, or
- **`collection:import`** from JSON/CSV:

  ```bash
  vendor/bin/tcms collection:import blog posts.json
  ```

  JSON is an array of objects conforming to the collection's schema:

  ```json
  [ { "id": "hello", "title": "Hello", "body": "..." } ]
  ```
- **`jumpstart:import`** for full-site bulk seeding.

## Export / query

```bash
vendor/bin/tcms collection:query blog --json        # filters + pagination
vendor/bin/tcms collection:export blog --json        # JSON | CSV | ZIP
vendor/bin/tcms object:export blog hello             # single object (+assets as ZIP)
```

## Repair

If an index or file metadata gets out of sync:

```bash
vendor/bin/tcms repair:index blog
vendor/bin/tcms repair:files
```

Exhaustive reference: `vendor/totalcms/cms/resources/docs/{collections,schemas,fields}/`.
