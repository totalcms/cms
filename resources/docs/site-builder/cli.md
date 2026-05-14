---
title: "Builder CLI Commands"
description: "CLI reference for the Site Builder: scaffold sites from starter templates, install a frontend pipeline, list registered routes, and manage template version history."
since: "3.5.0"
---

# Builder CLI Commands

The Site Builder provides four CLI commands: scaffolding from starters, installing a frontend asset pipeline, inspecting registered routes, and managing template version history.

| Command | Purpose |
|---------|---------|
| [`builder:init`](#builderinit) | Scaffold a new site from a starter template |
| [`builder:frontend`](#builderfrontend) | Install a Vite-based frontend asset pipeline |
| [`builder:routes`](#builderroutes) | List every route the page router would serve, with conflicts flagged |
| [`builder:history`](#builderhistory) | List, view, or restore template snapshot versions |

All commands accept `--json` for machine-readable output.

## `builder:init`

Scaffold a new site from a bundled starter template.

```bash
tcms builder:init business
tcms builder:init --list
tcms builder:init blog --force
tcms builder:init --json
```

### What It Does

1. Copies template files (layouts, pages, partials) from the starter into `tcms-data/builder/`
2. Creates the `builder-pages` collection with the `builder-page` schema (if it doesn't exist)
3. Imports the starter's `jumpstart.json` — pages, plus any supporting schemas, collections, and sample objects
4. (with `--frontend`) installs the Vite frontend scaffold — same as running `tcms builder:frontend`

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `starter` | No | Starter template name. Omit to list available starters. |

### Options

| Option | Description |
|--------|-------------|
| `--list, -l` | List available starters and exit |
| `--force, -f` | Overwrite existing template files (existing page records in the collection are left alone — JumpStart skips objects that already exist) |
| `--frontend` | Also install the Vite frontend scaffold (equivalent to running `tcms builder:frontend`) |
| `--json` | Output result as JSON |

### Starter Data

Every starter includes a `jumpstart.json` next to its `manifest.json`. The CLI runs it through the JumpStart importer after templates are copied so pages and any seed content land in the right places. For the **blog** starter that means 5 pages + 5 sample posts; for **business** that means 5 pages + a `service` schema + a `services` collection + 4 sample services.

If the import fails (e.g., schema validation), the scaffold itself still succeeds — templates are already on disk, and you can re-run the import manually with `tcms jumpstart:import resources/builder/starters/<name>/jumpstart.json`.

### `--frontend` Behavior

Convenience flag for the greenfield happy path. Runs `tcms builder:frontend` immediately after the scaffold completes, installing the Vite scaffold into `<projectRoot>/frontend/`. If you don't pass `--frontend`, you can always add the pipeline later — see [`builder:frontend`](#builderfrontend).

### `--force` Behavior

By default, scaffolding aborts if any page templates already exist. With `--force`:

- **Template files** are overwritten (existing files replaced with the starter's versions)
- **Asset files** in the docroot are overwritten as well

Page records and the order file are left alone — the JumpStart importer skips objects that already exist, so a re-init won't clobber edits made in the admin. To reset a page, delete it first then re-run, or import the page directly with `tcms jumpstart:import`.

`--force` is destructive against template files — back up your project first if you've made customizations.

### Available Starters

| Starter | Pages | Description |
|---------|-------|-------------|
| `minimal` | Home | Single page with clean layout |
| `business` | Home, About, Services, Contact | Professional business site |
| `blog` | Home, Blog, Blog Post, About | Blog-focused site with dynamic post routing |
| `portfolio` | Home, Work, About, Contact | Portfolio site with project cards |

### Example Workflow

```bash
# See what's available
tcms builder:init --list

# Scaffold a business site
tcms builder:init business

# Visit your site — routing is automatic, no generation step needed
```

Since the Site Builder uses middleware-based routing, pages are routed dynamically from the collection data. There is no generation or deployment step — pages work immediately after creating them in the admin.

## `builder:frontend`

Install a Vite-based frontend asset pipeline scaffold into your project. Idempotent — safe to re-run, won't overwrite customizations unless you pass `--force`.

```bash
tcms builder:frontend
tcms builder:frontend --force
tcms builder:frontend --json
```

### What It Does

Copies the contents of `resources/builder/frontend/` into `<projectRoot>/frontend/`:

- `vite.config.js` — emits hashed assets to `../public/assets/` with manifest
- `package.json` — `dev` / `build` / `watch` scripts; Vite as the only dependency
- `src/css/style.css` — minimal starter stylesheet
- `src/js/app.js` — minimal entry point
- `README.md` — quick reference for the install/build workflow
- `.gitignore` — excludes `node_modules/`, `dist/`, etc.

After running, `cd frontend && npm install && npm run build` produces compiled assets at `public/assets/` that T3's `cms.builder.css()` and `cms.builder.js()` Twig helpers automatically resolve via the manifest.

### Options

| Option | Description |
|--------|-------------|
| `--force, -f` | Overwrite existing files in `frontend/` (use with care — any customizations are lost) |
| `--json` | Output result as JSON |

### Idempotency

The default behavior is **skip files that already exist**, so re-running `builder:frontend` is safe — it only adds missing files. The result tells you what was skipped and reminds you to re-run with `--force` if you want a hard reset.

### When to Use This vs. `--frontend` on `builder:init`

| Scenario | Use |
|----------|-----|
| Greenfield project, scaffolding for the first time | `tcms builder:init <starter> --frontend` |
| Existing project, adding a frontend pipeline now | `tcms builder:frontend` |
| Pull missing files after the scaffold was edited | `tcms builder:frontend` (skips existing) |
| Reset the scaffold to upstream defaults | `tcms builder:frontend --force` |

See [Frontend Assets](docs/site-builder/frontend) for the full asset-pipeline reference (Sass, Tailwind, etc.).

## `builder:routes`

Print every route the page router would serve, in priority order, with duplicates flagged. Useful for auditing a site, hunting down route conflicts, and confirming that a collection URL is actually being matched the way you expect.

```bash
tcms builder:routes
tcms builder:routes --json
```

### What It Shows

For each route:

| Column | Description |
|--------|-------------|
| **Route** | The effective pattern the router would match |
| **Source** | `page` (builder page) or `collection` (collection URL) |
| **ID** | Page id or collection id |
| **Template** | Template path that gets rendered on a match |
| **Status** | HTTP status code the page returns |
| **Notes** | `draft` if excluded from routing, `⚠ duplicate` if another route declares the same pattern |

### Effective Routes for Collections

Collection URLs are normalized for display so you see what the router actually matches:

| Stored URL | Effective Route |
|-----------|----------------|
| `/blog` | `/blog/{id}` (router auto-appends an id segment) |
| `/blog/{{ id }}` | `/blog/{id}` (Twig syntax → standard) |
| `/products/{{ category }}/{{ id }}` | `/products/{category}/{id}` |

This makes it easy to spot when a builder page route like `/blog/{id}` collides with a collection URL `/blog` — both produce `/blog/{id}` and the duplicate flag fires.

### Example Output

```
Route                Source     ID         Template               Status  Notes
/                    page       home       pages/index.twig       200
/about               page       about      pages/about.twig       200
/blog                page       blog-list  pages/blog-list.twig   200
/blog/{id}           page       blog-post  pages/blog-post.twig   200
/blog/{id}           collection blog       pages/blog.twig        200     ⚠ duplicate
/maintenance         page       offline    pages/503.twig         503     draft

1 duplicate route(s) detected.
```

The duplicate above means both a builder page (`/blog/{id}`) and the blog collection's URL (also `/blog/{id}` after normalization) compete for the same pattern. The builder page wins because it's checked first, but the collection URL is unreachable — pick one or the other.

## `builder:history`

List, view, or restore snapshot versions of a builder template. Every template save captures a snapshot of the previous content; this command exposes that history at the CLI.

```bash
# List versions
tcms builder:history pages/about

# View a specific snapshot
tcms builder:history pages/about --show=1714588200

# Restore the snapshot
tcms builder:history pages/about --restore=1714588200
```

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `path` | Yes | Template path without extension (e.g. `pages/about`, `layouts/default`, `partials/nav`) |

### Options

| Option | Description |
|--------|-------------|
| `--show=<timestamp>` | Print the snapshot's contents to stdout |
| `--restore=<timestamp>` | Restore the snapshot — overwrite the current template file |
| `--json` | Output result as JSON |

### List Mode (default)

With no options, lists all snapshots for the template, newest first:

```
Versions for pages/about:

Timestamp    Date                 Age
----------   -------------------  ----------
1714588200   2026-05-01 10:30:00  2h ago
1714501800   2026-04-30 10:30:00  1d ago
1714415400   2026-04-29 10:30:00  2d ago

Restore with: tcms builder:history pages/about --restore=<timestamp>
```

### Show Mode (`--show`)

Prints the verbatim contents of the snapshot to stdout — useful for diffing against the current file:

```bash
tcms builder:history pages/about --show=1714501800 > /tmp/old.twig
diff /tmp/old.twig tcms-data/builder/pages/about.twig
```

### Restore Mode (`--restore`)

Restores the snapshot to be the current template content. The restore is **reversible** — saving captures a fresh snapshot of the current contents before overwriting, so you can always undo a restore by restoring the new newest timestamp.

```bash
# Roll back to yesterday's version
tcms builder:history pages/about --restore=1714501800
# → Restored to version 2026-04-30 10:30:00

# Decide it was a mistake — list to find the snapshot from the restore
tcms builder:history pages/about

# Restore the version that was current before the rollback
tcms builder:history pages/about --restore=<that-newest-timestamp>
```

### Storage and Retention

Snapshots live at `tcms-data/builder/.history/{path}/{timestamp}.twig`. The 50 newest snapshots per template are retained; older ones are pruned automatically on each save. You don't need to manage them manually.

See [Template History](docs/site-builder/admin#template-history) for the same workflow from the admin UI perspective.

## See Also

- [Site Builder Overview](docs/site-builder/overview)
- [Builder Admin UI](docs/site-builder/admin)
- [Starter Templates](docs/site-builder/starters)
