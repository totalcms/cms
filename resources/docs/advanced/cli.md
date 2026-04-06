---
title: "CLI Commands"
description: "Complete reference for the Total CMS CLI tool (tcms). Manage schemas, collections, objects, cache, jobs, sync, and JumpStart from the command line."
---

# CLI Commands

The `tcms` CLI tool is the command-line interface to Total CMS. It exposes core CMS services as composable terminal commands, designed for both AI coding agents and human developers.

## Running the CLI

```bash
php resources/bin/tcms <command> [options] [arguments]
```

All commands support a `--json` flag that outputs valid JSON to stdout. This is the contract AI agents rely on — no decorative output, no progress bars, no color codes.

## Global Options

| Option | Description |
|--------|-------------|
| `--json` | Output as JSON (for AI agent compatibility) |
| `-v` | Verbose output |
| `-q` | Quiet mode (errors only) |
| `-h, --help` | Display help for a command |
| `-V, --version` | Display version |

---

## Site Information

### `info`

Show site status, version, edition, license, collection count, and cache backend.

```bash
tcms info
tcms info --json
```

**JSON output:**
```json
{
    "version": "3.2.2",
    "build": "7f080a63",
    "edition": "pro",
    "license": { "valid": true, "trial": false, "trialDaysRemaining": null },
    "domain": "example.com",
    "collections": { "total": 12 },
    "schemas": { "reserved": 22, "custom": 4 },
    "cache": { "backend": "apcu" }
}
```

---

## Schema Commands

### `schema:list`

List all schemas.

```bash
tcms schema:list
tcms schema:list --custom
tcms schema:list --reserved
tcms schema:list --category=Commerce
tcms schema:list --json
```

| Option | Description |
|--------|-------------|
| `--custom` | Only show custom schemas |
| `--reserved` | Only show reserved (built-in) schemas |
| `--category` | Filter by category |

### `schema:get`

Show full schema definition including properties, types, and field configurations.

```bash
tcms schema:get blog
tcms schema:get blog --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Schema ID |

### `schema:export`

Export a schema to a JSON file.

```bash
tcms schema:export blog --output=blog-schema.json
tcms schema:export blog
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Schema ID |

| Option | Description |
|--------|-------------|
| `--output, -o` | Output file path (omit for stdout) |

### `schema:import`

Import a schema from a JSON file. Creates or updates the schema.

```bash
tcms schema:import my-schema.json
tcms schema:import my-schema.json --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `file` | Yes | Path to schema JSON file |

---

## Collection Commands

### `collection:list`

List all collections with their schema and object count.

```bash
tcms collection:list
tcms collection:list --schema=blog
tcms collection:list --category=Content
tcms collection:list --json
```

| Option | Description |
|--------|-------------|
| `--schema` | Filter by schema type |
| `--category` | Filter by category |

### `collection:get`

Show collection metadata including schema, sort order, and object count.

```bash
tcms collection:get blog
tcms collection:get blog --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Collection ID |

### `collection:query`

Query a collection's index with filtering, searching, sorting, and pagination.

```bash
tcms collection:query posts --search="photography" --limit=5
tcms collection:query posts --include="featured:true" --sort="-date"
tcms collection:query posts --exclude="draft:true" --limit=10 --offset=20
tcms collection:query posts --filter="title:drone" --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Collection ID |

| Option | Description |
|--------|-------------|
| `--search, -s` | Full-text search query |
| `--filter` | Contains filter (`field:value`) |
| `--include` | Include filter (`field:value,field:value`) |
| `--exclude` | Exclude filter (`field:value,field:value`) |
| `--sort` | Sort by property (prefix with `-` for descending) |
| `--limit, -l` | Maximum results (default: 20) |
| `--offset, -o` | Number of results to skip (default: 0) |

**JSON output:**
```json
{
    "total": 37,
    "offset": 0,
    "limit": 5,
    "results": [...]
}
```

### `collection:export`

Export a collection to JSON, CSV, or ZIP.

```bash
tcms collection:export blog --output=blog.json
tcms collection:export blog --format=csv --output=blog.csv
tcms collection:export blog --format=zip --output=blog-backup.zip
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Collection ID |

| Option | Description |
|--------|-------------|
| `--format, -f` | Export format: `json`, `csv`, or `zip` (default: json) |
| `--output, -o` | Output file path (omit for stdout; zip generates a default filename) |

The `zip` format includes all object JSON files and their associated media/assets. JSON export uses streaming for large collections when `--output` is specified.

### `collection:import`

Import objects into a collection from a JSON or CSV file.

```bash
tcms collection:import blog posts.json
tcms collection:import blog data.csv
tcms collection:import blog posts.json --update
tcms collection:import blog posts.json --format=json --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Collection ID |
| `file` | Yes | Path to JSON or CSV file |

| Option | Description |
|--------|-------------|
| `--format, -f` | Import format: `json` or `csv` (auto-detected from extension) |
| `--update` | Update existing objects instead of skipping |

---

## Object Commands

### `object:list`

List object IDs in a collection.

```bash
tcms object:list blog
tcms object:list blog --limit=10 --offset=20
tcms object:list blog --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |

| Option | Description |
|--------|-------------|
| `--limit, -l` | Maximum results |
| `--offset, -o` | Number of results to skip |

### `object:get`

Fetch a single object with all its properties.

```bash
tcms object:get blog my-post
tcms object:get blog my-post --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |
| `id` | Yes | Object ID |

### `object:export`

Export a single object as JSON or ZIP (with assets).

```bash
tcms object:export blog my-post --output=my-post.json
tcms object:export blog my-post --format=zip --output=my-post.zip
tcms object:export blog my-post
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |
| `id` | Yes | Object ID |

| Option | Description |
|--------|-------------|
| `--format, -f` | Export format: `json` or `zip` (default: json) |
| `--output, -o` | Output file path (omit for stdout; zip generates a default filename) |

---

## Deck Commands

### `deck:import`

Import items into a deck property from a JSON or CSV file.

```bash
tcms deck:import invoices inv-001 items line-items.json
tcms deck:import invoices inv-001 items line-items.csv --update
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |
| `object` | Yes | Object ID |
| `property` | Yes | Deck property name |
| `file` | Yes | Path to JSON or CSV file |

| Option | Description |
|--------|-------------|
| `--format, -f` | Import format: `json` or `csv` (auto-detected from extension) |
| `--update` | Update existing deck items instead of skipping |

---

## JumpStart Commands

### `jumpstart:export`

Export all site data (schemas, collections, objects, templates) as a JumpStart file.

```bash
tcms jumpstart:export --output=my-site.json
tcms jumpstart:export --name="My Site" --description="Full site export"
tcms jumpstart:export --json
```

| Option | Description |
|--------|-------------|
| `--name` | Name for the export |
| `--description` | Description for the export |
| `--output, -o` | Output file path (generates default filename if omitted) |

### `jumpstart:import`

Import a JumpStart file to set up schemas, collections, objects, and templates.

```bash
tcms jumpstart:import my-site.json
tcms jumpstart:import my-site.json --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `file` | Yes | Path to JumpStart JSON file |

---

## Sync Commands

Push and pull schemas and templates between a local development instance and a production server. Configure the production server URL and API key in **Settings > Sync** in the admin dashboard.

### `push`

Push schemas and templates to the production server.

```bash
tcms push
tcms push --dry-run
tcms push --schemas=blog,products
tcms push --templates=blog-post,sidebar
tcms push --schemas=blog --templates=blog-post --dry-run
```

| Option | Description |
|--------|-------------|
| `--schemas` | Comma-separated schema IDs to push (default: all custom) |
| `--templates` | Comma-separated template IDs to push (default: all custom) |
| `--dry-run` | Preview what would be pushed without sending |

### `pull`

Pull schemas and templates from the production server.

```bash
tcms pull
tcms pull --dry-run
tcms pull --schemas=blog
tcms pull --templates=blog-post,sidebar
```

| Option | Description |
|--------|-------------|
| `--schemas` | Comma-separated schema IDs to pull (default: all) |
| `--templates` | Comma-separated template IDs to pull (default: all) |
| `--dry-run` | Preview what would be pulled without applying |

**What gets synced:** Custom schemas and custom templates only.

**What never gets synced:** Content/objects, media/images, system settings, API keys, reserved schemas.

---

## Cache & Jobs

### `cache:clear`

Clear all caches. When run from CLI, a signal file is written so the web process clears its APCu cache on the next request.

```bash
tcms cache:clear
tcms cache:clear --json
```

### `jobs:process`

Process the pending job queue. This is typically run via cron.

```bash
tcms jobs:process
tcms jobs:process -v
tcms jobs:process --memory=1G
tcms jobs:process --json
```

| Option | Description |
|--------|-------------|
| `--memory, -m` | Memory limit (default: 512M) |
| `-v` | Verbose output with per-job details |

**Cron setup:**
```bash
* * * * * php /path/to/resources/bin/tcms jobs:process
```
