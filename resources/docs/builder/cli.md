---
title: "Builder CLI Commands"
description: "CLI reference for the Site Builder: generate stubs, scaffold from starters, and watch for changes."
since: "3.3.0"
---

# Builder CLI Commands

The Site Builder adds three CLI commands for generating stubs, scaffolding sites, and watching for changes.

---

## `builder:generate`

Generate PHP stubs for all published pages in the `builder-pages` collection.

```bash
tcms builder:generate
tcms builder:generate --dry-run
tcms builder:generate --no-clean
tcms builder:generate --json
```

### What It Does

1. Reads all page objects from the `pages` collection
2. Filters to pages with `status: published` (drafts are skipped)
3. Writes or updates `tcms-boot.php` in the docroot
4. For each published page, writes a stub file at the mapped path (see [Path Mapping](docs/builder/overview#path-mapping))
5. Optionally removes orphan stubs that no longer correspond to a published page

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be generated without writing any files |
| `--no-clean` | Skip removing orphan stubs (overrides the `cleanOrphans` setting) |
| `--json` | Output result as JSON |

### Output

```
Generated 4 stub(s)
  + index.php
  + about/index.php
  + services/index.php
  + contact/index.php

Skipped 1 draft page(s)
  - /blog/draft-post

Cleaned 1 orphan stub(s)
  x old-page/index.php
```

### JSON Output

```json
{
    "success": true,
    "generated": 4,
    "skipped": 1,
    "cleaned": 1,
    "errors": [],
    "files": [
        "index.php",
        "about/index.php",
        "services/index.php",
        "contact/index.php"
    ]
}
```

### Orphan Cleanup

When `cleanOrphans` is enabled (the default), the generator scans the docroot for PHP files containing the `tcms_render(` signature. Any such file that doesn't correspond to a currently published page is removed. This safely cleans up stubs for deleted or unpublished pages without touching non-builder files.

The boot file (`tcms-boot.php`) is never removed by cleanup.

### Error Cases

| Error | Cause |
|-------|-------|
| "Docroot is not configured" | No `docroot` in settings and server hasn't persisted `DOCUMENT_ROOT` yet. Visit the site in a browser first, or set `builder.docroot` in config. |
| "No builder-pages collection found" | Create a collection with ID `builder-pages` using the `builder-page` schema, or run `tcms builder:init`. |

---

## `builder:init`

Scaffold a new site from a bundled starter template.

```bash
tcms builder:init business
tcms builder:init --list
tcms builder:init blog --force
tcms builder:init --json
```

### What It Does

1. Copies template files (layouts, pages, partials) from the starter into `tcms-data/templates/`
2. Creates the `pages` collection with the `page` schema (if it doesn't exist)
3. Creates page objects from the starter's manifest
4. Enables the builder in settings

### Arguments

| Argument | Description |
|----------|-------------|
| `starter` | Starter template name. Omit to list available starters. |

### Options

| Option | Description |
|--------|-------------|
| `--list, -l` | List available starters and exit |
| `--force, -f` | Overwrite existing templates if present |
| `--json` | Output result as JSON |

### Available Starters

| Starter | Pages | Description |
|---------|-------|-------------|
| `minimal` | Home | Single page with clean layout, nav, and footer |
| `business` | Home, About, Services, Contact | Professional business site with card grid layout |
| `blog` | Home, Blog, Blog Post, About | Blog-focused site with post listing and single post templates |
| `portfolio` | Home, Work, About, Contact | Portfolio site with project card grid |

### Example Workflow

```bash
# See what's available
tcms builder:init --list

# Scaffold a business site
tcms builder:init business

# Generate the stubs
tcms builder:generate

# Visit your site in a browser
```

### Output

```
Scaffolding business starter...

Scaffolded 'Business' starter: 8 files copied, 4 pages created

Next steps:
  1. Edit your templates in tcms-data/templates/
  2. Add or edit pages in the admin under Collections > Pages
  3. Run tcms builder:generate to write stubs to your docroot
```

### What Each Starter Includes

Every starter ships with:

- **A layout** (`layouts/default.twig`) — full HTML5 structure with `{% block content %}`, site name from `cms.config('domain')`
- **Page templates** (`pages/*.twig`) — each extends the layout and has placeholder content
- **A nav partial** (`partials/nav.twig`) — dynamically lists published pages from the `builder-pages` collection, sorted by the `sort` field
- **A footer partial** (`partials/footer.twig`) — copyright with current year

Templates use inline `<style>` tags for basic styling so they look reasonable out of the box. Replace the inline styles with your own CSS build pipeline (Vite, Sass, plain CSS — your choice).

---

## `builder:watch`

Watch for changes to the `builder-pages` collection and auto-regenerate stubs.

```bash
tcms builder:watch
tcms builder:watch --interval=5
```

### What It Does

Polls the `tcms-data/pages/` directory for file changes (new pages, deleted pages, modified status). When a change is detected, it runs `builder:generate` automatically.

### Options

| Option | Description |
|--------|-------------|
| `--interval, -i` | Poll interval in seconds (default: 2) |

### Output

```
Watching for page changes...
  Directory: /path/to/tcms-data/pages
  Interval:  2s
  Press Ctrl+C to stop

[14:32:15] Change detected, regenerating...
[14:32:15] Generated 5 stub(s)
[14:35:22] Change detected, regenerating...
[14:35:22] Generated 4 stub(s)
[14:35:22] Cleaned 1 orphan(s)
```

### When to Use

Use `builder:watch` during local development when you're actively adding or modifying pages in the admin. It saves you from running `builder:generate` manually after each change.

For production, use the **Auto-Generate** setting instead (Settings > Builder > Auto-Generate Stubs), which regenerates stubs on save within the admin UI.

---

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder Admin UI](docs/builder/admin)
- [Starter Templates](docs/builder/starters)
