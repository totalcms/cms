---
title: "Builder CLI Commands"
description: "CLI reference for the Site Builder: scaffold sites from starter templates."
since: "3.3.0"
---

# Builder CLI Commands

The Site Builder provides a CLI command for scaffolding sites from starter templates.

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

1. Copies template files (layouts, pages, partials) from the starter into `tcms-data/builder/`
2. Creates the `builder-pages` collection with the `builder-page` schema (if it doesn't exist)
3. Creates page objects from the starter's manifest with routes and templates

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

### Note

Since the Site Builder uses middleware-based routing, pages are routed dynamically from the collection data. There is no generation or deployment step — pages work immediately after creating them in the admin.

---

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder Admin UI](docs/builder/admin)
- [Starter Templates](docs/builder/starters)
