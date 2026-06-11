# tcms CLI reference

> This is a summary. For full detail, run `vendor/bin/tcms <command> --help`, and
> read the on-disk docs that ship with the package at
> `vendor/totalcms/cms/resources/docs/` (or query the MCP server if connected).

Binary: `vendor/bin/tcms`. Global flags: `--json` (machine-readable), `-n`
(no-interaction), `-q` (quiet). Run `vendor/bin/tcms <command> --help` for any command.

## --json contract

`--json` prints a JSON array or object to stdout and nothing else, e.g.:

```bash
vendor/bin/tcms collection:list --json
# => [ { "id": "blog", "schema": "blog", "sortBy": "id", ... }, ... ]
```

Scripting recipe — list collection IDs (needs `jq`):

```bash
vendor/bin/tcms collection:list --json | jq -r '.[].id'
```

## Commands by domain

### Site status
- `info` — site status, version, configuration
- `cache:clear` — clear all caches (run after template edits if stale)
- `deploy` — post-deploy cleanup: wipe DI container, clear caches, run migrations

### Collections
- `collection:list` — list all collections
- `collection:get <id>` — collection metadata
- `collection:query <id>` — query with filters + pagination
- `collection:export <id>` — export to JSON, CSV, or ZIP
- `collection:import <id> <file>` — import objects from JSON or CSV

### Objects
- `object:list <collection>` — list object IDs
- `object:get <collection> <id>` — fetch one object
- `object:export <collection> <id>` — export one object as JSON or ZIP (with assets)
- `object:delete <collection> <id>` — delete one object (updates the index)
- **No `object:create`/`object:set`.** Create content via admin UI,
  `collection:import`, or `jumpstart:import`.

### Schemas
- `schema:list` — list all schemas
- `schema:get <id>` — schema details
- `schema:export <id> <file>` — export a schema to JSON
- `schema:import <file>` — import a schema from JSON

### Site Builder
- `builder:init [starter]` — scaffold from a starter (`business`/`blog`/`portfolio`/`minimal`); `--frontend`, `--force`, `--list`
- `builder:frontend` — install the Vite frontend pipeline
- `builder:routes` — list every route the page router serves; flags conflicts
- `builder:history` — list/restore snapshot versions of a builder template

### Bulk data
- `jumpstart:export` / `jumpstart:import` — full-site data import/export
- `deck:import` — import items into a deck property from JSON/CSV
- `rss:import` — import an RSS/Atom/JSON feed into a collection

### Extensions
- `extension:list` / `extension:enable <id>` / `extension:disable <id>` / `extension:remove <id>`

### Maintenance
- `repair:index <collection>` — rebuild `.index.json` + count from objects on disk
- `repair:files` — rebuild blanked file/image/gallery metadata from files on disk
- `search:reindex` — re-index against the active search provider
- `jobs:process` / `automations:process` — process queued jobs / fire due automations
- `update:check` / `update:apply` / `update:rollback`
- `pull` / `push` — sync schemas + templates with the production server

## collection:import shape

JSON import is an array of objects matching the collection's schema. Example for
`builder-pages`:

```json
[
  { "id": "about", "title": "About", "route": "/about", "template": "pages/page.twig", "draft": false }
]
```

```bash
vendor/bin/tcms collection:import builder-pages about.json
```
