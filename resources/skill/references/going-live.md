# Going live: source of truth and syncing

> The lifecycle rule everything else follows from: **before launch, the local
> project is the source of truth. After launch, the production server is.**
> Full sync docs: `vendor/totalcms/cms/resources/docs/operations/sync.md`.

## Before launch

Content, schemas, and templates all live in the local project. Seed content via
`collection:import` / `jumpstart:import`, iterate freely, deploy the whole
`tcms-data/` directory with the site.

## After launch

Editors (and MCP agents) write to production. Local copies of **content** go
stale by design — do not re-import old seed files over live data, and delete
seed files once they have served their purpose. From now on:

- **Content changes** happen on the server (admin UI, REST, MCP) — or reach it
  via `push`. To work with live content locally, `pull` it down.
- **Templates** stay git-managed when a project-root `builder/` directory
  exists — deploy them through git, not sync. (The Sync Manager hides the
  template section on git-managed sites for this reason.)
- **Schemas and collection settings** are developed locally and pushed.

## push / pull

`vendor/bin/tcms push` and `pull` sync schemas, templates, objects, and
collection settings with the configured production server.

```bash
vendor/bin/tcms pull --dry-run          # ALWAYS dry-run first — shows per-item
                                        # status (new / differs / likely newer)
vendor/bin/tcms push --schemas=blog     # exclusive: ONLY these schemas move
vendor/bin/tcms push --collection-meta=blog   # collection SETTINGS (not objects)
vendor/bin/tcms pull --collections=blog       # collection OBJECTS
```

Semantics that matter:

- **Naming any filter excludes every other category.** A bare `push`/`pull` is
  a full mirror; `--schemas=x` moves only that.
- `--collections` means **objects**; `--collection-meta` means **settings**
  (URL, MCP card, sitemap…). Different flags, different data.
- **Counters never sync.** `count`, `totalObjects`, and `lastUpdated` are
  environment-local; the receiving side always keeps its own.
- **The receiving side backs up before overwriting** — snapshots land in
  `tcms-data/.system/backups/{schemas,objects,collections}/`.
- Timestamps (`updated`) are hints for direction, not locks: a dry-run showing
  "remote likely newer" means pull it before you push over it.

The admin Sync Manager (Utilities → Sync) is the same engine with checkboxes —
its diff preview and the CLI dry-run report identical data.
