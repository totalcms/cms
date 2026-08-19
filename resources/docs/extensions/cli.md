---
title: "CLI Commands"
description: "Complete reference for the Total CMS CLI tool (tcms). Manage schemas, collections, objects, cache, jobs, sync, and JumpStart from the command line."
since: "3.5.0"
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

### `schema:lint`

Lint stored schemas without saving anything. `schema:import` validates on the way in, but schemas edited in place (by hand or by an AI agent) are never re-validated — the linter closes that gap. Errors are structural problems that break at runtime: no `id` property defined, `required`/`index` entries naming undefined properties, `inheritFrom` pointing at a missing schema, deck/card `schemaref` targets that don't exist or contain deck-incompatible types, and meta-schema violations. Warnings flag agent-legibility gaps — properties without help text (help feeds the MCP tool catalog) and schemas without a description.

```bash
tcms schema:lint                 # all custom schemas
tcms schema:lint blog            # one schema (reserved schemas allowed by ID)
tcms schema:lint --strict        # warnings also fail the run
tcms schema:lint --json          # machine-readable report
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | No | Schema ID (default: lint all custom schemas) |

| Option | Description |
|--------|-------------|
| `--strict` | Treat warnings as failures |

Exit code is `0` when no errors were found (warnings allowed), `1` when errors were found — or warnings under `--strict` — so it slots into CI.

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

### `object:create`

Create one object from a JSON file (or stdin with `-`). The object goes through the full save pipeline — schema defaults fill in, validation runs, the index updates, and the `object.created` event fires — exactly as if it were saved from the admin. Refuses to overwrite an existing object, and refuses a JSON array (use `collection:import` for bulk data).

```bash
tcms object:create blog my-post.json
echo '{"id":"hello","title":"Hello"}' | tcms object:create blog -
tcms object:create blog my-post.json --json    # echoes the saved object
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |
| `file` | Yes | Path to a JSON file holding one object, or `-` for stdin |

### `object:patch`

Merge changes into an existing object, leaving every field you don't mention untouched. Like `object:create`, it goes through the full save pipeline — validation runs, the collection index updates, and `object.updated` fires — so it stays consistent with the admin in a way that editing the JSON file by hand does not.

Use this for a targeted change to one object. `object:create` only makes new objects, and `collection:import` works in bulk.

```bash
tcms object:patch blog my-post patch.json
echo '{"title":"New Title"}' | tcms object:patch blog my-post -
tcms object:patch blog my-post patch.json --json    # echoes the saved object
```

**The merge is shallow.** Patching a structured field from the top level replaces it wholesale, which will quietly discard the parts you didn't mention. Target the property instead to merge *into* it:

```bash
# Replaces the whole image field — name, size and dimensions are lost
echo '{"image":{"alt":"A description"}}' | tcms object:patch image social -

# Changes only alt, preserving everything else on the field
echo '{"alt":"A description"}' | tcms object:patch image social - --property=image
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |
| `id` | Yes | Object ID |
| `file` | Yes | Path to a JSON file holding the fields to merge, or `-` for stdin |

| Option | Description |
|--------|-------------|
| `--property` | Merge into this property instead of the top level, preserving its other keys |

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

### `object:delete`

Delete a single object. The deletion goes through the same index-aware path as the admin UI, so the collection's `.index.json` and object count stay consistent — unlike removing the flat file by hand, which leaves them stale.

```bash
tcms object:delete blog my-post
tcms object:delete blog my-post --force
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | Yes | Collection ID |
| `id` | Yes | Object ID |

| Option | Description |
|--------|-------------|
| `--force, -f` | Skip the confirmation prompt (required for non-interactive/CI use) |

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

## Feed Commands

### `rss:import`

Queue every entry from an RSS, Atom, or JSON feed into a target collection. The CLI counterpart to the **Utilities → Import RSS** admin page. Designed for cron — the admin form has a "Schedule with cron" panel that builds the exact command line for the configured import so operators can paste it directly into crontab.

```bash
# Basic import — auto field mapping, items queued as drafts
tcms rss:import https://example.com/feed.xml blog

# Publish immediately
tcms rss:import https://example.com/feed.xml blog --no-draft

# Drain the queue in the same cron run
tcms rss:import https://example.com/feed.xml blog && tcms jobs:process
```

| Argument | Required | Description |
|----------|----------|-------------|
| `url` | Yes | RSS / Atom / JSON Feed URL |
| `collection` | Yes | Target collection ID |

| Option | Description |
|--------|-------------|
| `--draft` / `--no-draft` | Queue items as drafts (default) or publish immediately |
| `--map, -m` | Field mapping in the form `feedField=collectionField`. Repeat the option or comma-separate within one value. See below. |
| `--user-agent` | User-Agent for the feed request. See below. |
| `--json` | Output JSON (success status + count) |

#### When a feed returns 403

Feed requests identify themselves as `TotalCMS/{version} (+https://totalcms.co)`. Some hosts — Cloudflare-fronted sites in particular — block requests from unrecognized or generic clients outright, and a feed that opens fine in a browser can still return `403` to a server.

If a host rejects the default, `--user-agent` sets your own:

```bash
tcms rss:import https://example.com/feed.xml blog \
  --user-agent "AcmeNews/1.0 (+https://acme.example)"
```

Identify yourself honestly — a contactable URL is what gets a well-run site to allowlist you. Impersonating a browser tends to be treated as evasion and blocked harder.

#### Field mapping

The importer maps these eight feed-side fields to your collection's properties:

| Feed field | Default collection property |
|---|---|
| `title` | `title` |
| `content` | `content` |
| `summary` | `summary` |
| `date` | `date` |
| `author` | `author` |
| `categories` | `categories` |
| `link` | `media` |
| `image` | `image` |

To override a default mapping, pass `--map feedField=collectionProperty`. To **drop** a field entirely (don't write it to the object), map it to an empty string with `--map feedField=`.

```bash
# Map the feed's `title` into your schema's `heading` property,
# and the feed's `content` into your schema's `body` property.
tcms rss:import https://example.com/feed.xml blog \
  --map title=heading \
  --map content=body

# Same thing, comma-separated single argument (handy in crontab one-liners).
tcms rss:import https://example.com/feed.xml blog --map "title=heading,content=body"

# Drop the link/categories fields entirely; remap the rest.
tcms rss:import https://example.com/feed.xml news \
  --map title=headline \
  --map content=body \
  --map image=hero \
  --map link= \
  --map categories=

# Realistic news-import recipe targeting a schema with
# `heading`, `body`, `excerpt`, `published`, `byline`, `tags`, `hero`.
tcms rss:import https://example.com/feed.xml news --no-draft \
  --map title=heading \
  --map content=body \
  --map summary=excerpt \
  --map date=published \
  --map author=byline \
  --map categories=tags \
  --map image=hero
```

#### Cron example

```cron
# Hourly RSS pull — drain the queue right after queuing
0 * * * * /usr/local/bin/php /var/www/site/resources/bin/tcms rss:import "https://example.com/feed.xml" blog --no-draft --map "title=heading,content=body" && /usr/local/bin/php /var/www/site/resources/bin/tcms jobs:process
```

The admin UI's "Schedule with cron" panel under **Utilities → Import RSS** prints this command for you with your site's PHP and install paths already filled in — open the panel, copy, paste into crontab.

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
tcms push --collections=builder-pages
tcms push --schemas=blog --templates=blog-post --dry-run
```

| Option | Description |
|--------|-------------|
| `--schemas` | Comma-separated schema IDs to push |
| `--templates` | Comma-separated template IDs to push |
| `--collections` | Comma-separated allowlisted collection IDs whose objects to push |
| `--collection-meta` | Comma-separated collection IDs whose settings to push (any collection; counters never travel) |
| `--dry-run` | Compare both sides: per-item unchanged/differs/new status with newer-side hints |

### `pull`

Pull schemas, templates, and allowlisted collection objects from the production server.

```bash
tcms pull
tcms pull --dry-run
tcms pull --schemas=blog
tcms pull --templates=blog-post,sidebar
tcms pull --collections=builder-pages
```

| Option | Description |
|--------|-------------|
| `--schemas` | Comma-separated schema IDs to pull |
| `--templates` | Comma-separated template IDs to pull |
| `--collections` | Comma-separated allowlisted collection IDs whose objects to pull |
| `--collection-meta` | Comma-separated collection IDs whose settings to pull (any collection; counters never travel) |
| `--dry-run` | Compare both sides: per-item unchanged/differs/new status with newer-side hints |

**What gets synced:** Custom schemas, custom templates, collection settings (any collection — never its counters), and objects from five reserved collections — `builder-pages`, `mailer`, `mcp-prompt`, `dataviews`, `automations`. The collection list is hardcoded and cannot be extended.

**What never gets synced:** Objects in your own custom collections, media/images, system settings, API keys, reserved schemas. A custom collection's *schema* syncs; the objects inside it do not.

**Filter semantics:** a bare `tcms push` or `tcms pull` is a full mirror — every category travels. The moment any of `--schemas`, `--templates`, or `--collections` is given, the categories you did not mention are excluded entirely, so `tcms push --schemas=blog` moves the blog schema and nothing else.

**Backups:** before an overwrite lands, the receiving instance snapshots the current version to `tcms-data/.system/backups/{schemas,objects}/...` (ten most recent per item). See the [Sync guide](operations/sync) for details.

---

## Cache & Jobs

### `cache:clear`

Clear all caches.

```bash
tcms cache:clear
tcms cache:clear --json
```

**How this reaches the web server.** APCu is per-process, so a CLI run cannot clear the cache the web server is holding — the two never share memory. Instead, `cache:clear` clears what it can reach directly (filesystem, Redis, Memcached) and writes a signal file to `tcms-data/.system/.cache_invalidate`. The next request to hit the site replays that signal and clears APCu in the web process.

That means **the clear does not take effect until someone loads a page.** If you clear from a deploy script and immediately check the site, the very request you make is the one that applies it — so a single reload can still look stale. Load the page twice.

If a clear appears not to have worked, check whether the signal file is still sitting there:

```bash
ls tcms-data/.system/.cache_invalidate
```

Present means no request has replayed it yet. Gone means it was applied.

**Alternatives when you are iterating.** Two options avoid the round trip entirely:

- **Turn on Developer Mode** in the admin. Cache reads are bypassed outright while it is active, so you see fresh output on every request without clearing anything. This is the right choice while you are actively editing templates or content.
- **Hit the HTTP endpoint** at `/api/emergency/cache/clear`, which clears in the web process directly rather than by signal. It needs no login, and it is rate-limited to one call per IP every 15 minutes.

> Note the `/api` prefix — `/emergency/cache/clear` without it returns a 404.

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

---

### `automations:process`

Fire due **scheduled** automations. Runs on its **own** cron line, parallel to `jobs:process`, so a large import backlog in the job queue never delays a time-sensitive scheduled automation. Single-flight locked, so overlapping cron ticks can't double-fire the same run.

```bash
tcms automations:process
tcms automations:process --json
```

Webhook and event triggers do not depend on this command — webhooks fire on HTTP request and events fire when the originating write happens; their async runs are drained on the next tick.

**Cron setup** (add this as a second line, alongside `jobs:process`):
```bash
* * * * * php /path/to/resources/bin/tcms automations:process
```

---

## MCP Commands

See the [MCP Server guide](docs/mcp/server) for the full server documentation.

### `mcp:status`

Show the MCP server's operator-facing health: enabled state, public-access switch, edition gate, tool prefix, and the tool list each persona sees. Saved-query tools defined in collection MCP cards are included and annotated `(saved query)`.

```bash
tcms mcp:status
tcms mcp:status --json    # persona tool lists plus a schema_tools array
```

### `mcp:test`

Invoke a tool locally without going through the HTTP endpoint — useful for verifying a tool's output and persona visibility before an agent connects.

```bash
tcms mcp:test query_collection --params='{"collection":"blog","limit":3}'
tcms mcp:test query_collection --params='{"collection":"blog"}' --persona=public
```

---

## Maintenance Commands

### `repair:index`

Rebuild a collection's `.index.json` and `totalObjects` count from the objects on disk. Use this when the index has drifted from the actual files — for example after an object's flat file was added or removed out-of-band. (`search:reindex` only touches the search provider; `repair:files` only rebuilds file/image metadata.)

```bash
tcms repair:index blog
tcms repair:index --all
```

| Argument | Required | Description |
|----------|----------|-------------|
| `collection` | No | Collection ID (omit and use `--all` to rebuild every collection) |

| Option | Description |
|--------|-------------|
| `--all` | Rebuild the index for every collection |

---

## Update Commands

### `update:check`

Check for available updates from the license server.

```bash
tcms update:check
tcms update:check --json
```

**JSON output:**
```json
{
    "current": "3.2.2",
    "available": true,
    "version": "3.5.0",
    "releaseDate": "2026-04-10",
    "severity": "minor",
    "changelog": "New features and improvements",
    "downloadUrl": "/version/download/3.5.0"
}
```

### `update:apply`

Download and apply an available update. The site enters maintenance mode during the swap.

```bash
tcms update:apply
tcms update:apply --force
tcms update:apply --json
```

| Option | Description |
|--------|-------------|
| `--force` | Skip confirmation prompt |

The previous version is backed up automatically for rollback.

### `update:rollback`

Roll back to the previous version after a failed or unwanted update.

```bash
tcms update:rollback
tcms update:rollback --force
```

| Option | Description |
|--------|-------------|
| `--force` | Skip confirmation prompt |

Restores the backup directory created during the most recent update.

---

## Extension Commands

### `extension:list`

List all discovered extensions with their status.

```bash
tcms extension:list
tcms extension:list --json
```

**JSON output:**
```json
[
    {
        "id": "acme/seo-pro",
        "name": "SEO Pro",
        "version": "1.2.0",
        "enabled": true,
        "error": null
    }
]
```

### `extension:enable`

Enable a discovered extension.

```bash
tcms extension:enable acme/seo-pro
tcms extension:enable acme/seo-pro --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Extension ID (e.g. `vendor/extension-name`) |

### `extension:disable`

Disable an extension without removing it.

```bash
tcms extension:disable acme/seo-pro
tcms extension:disable acme/seo-pro --json
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Extension ID (e.g. `vendor/extension-name`) |

### `extension:remove`

Remove an extension's files. Extension data in `tcms-data` is preserved.

```bash
tcms extension:remove acme/seo-pro
tcms extension:remove acme/seo-pro --force
```

| Argument | Required | Description |
|----------|----------|-------------|
| `id` | Yes | Extension ID (e.g. `vendor/extension-name`) |

| Option | Description |
|--------|-------------|
| `--force, -f` | Skip confirmation prompt |
