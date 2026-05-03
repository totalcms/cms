---
title: "Builder CLI Commands"
description: "CLI reference for the Site Builder: scaffold sites from starter templates, list registered routes, and manage template version history."
since: "3.3.0"
---

# Builder CLI Commands

The Site Builder provides three CLI commands: scaffolding from starters, inspecting registered routes, and managing template version history.

| Command | Purpose |
|---------|---------|
| [`builder:init`](#builderinit) | Scaffold a new site from a starter template |
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
3. Creates page objects from the starter's manifest with routes and templates
4. Seeds the order file (`.order.json`) so the sidebar shows pages in the manifest's intended order on first visit

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `starter` | No | Starter template name. Omit to list available starters. |

### Options

| Option | Description |
|--------|-------------|
| `--list, -l` | List available starters and exit |
| `--force, -f` | Overwrite existing template files **and** update existing page records |
| `--json` | Output result as JSON |

### `--force` Behavior

By default, scaffolding aborts if any page templates already exist. With `--force`:

- **Template files** are overwritten (existing files replaced with the starter's versions)
- **Page records** are updated in place (existing pages are not skipped — they receive the starter's title/route/template)
- **Order file** is re-seeded from the manifest, so reordering done in the admin is overwritten

`--force` is destructive — back up your project first if you've made customizations.

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

See [Template History](docs/builder/admin#template-history) for the same workflow from the admin UI perspective.

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder Admin UI](docs/builder/admin)
- [Starter Templates](docs/builder/starters)
