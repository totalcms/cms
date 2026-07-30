---
title: "Sync"
description: "Push and pull schemas and templates between local development and production Total CMS instances using the CLI or admin dashboard."
since: "3.5.0"
---

# Sync

Sync lets you push schemas and templates from a local development instance to a production server, or pull them from production to your local environment. This enables a proper development workflow where you build and test locally, then deploy structural changes to production without touching content or media.

## What Gets Synced

- Custom schemas (`.schemas/` directory)
- Custom templates
- **Collection settings** — every collection's configuration (`.meta.json`): URL, MCP card, sitemap settings, access groups, schema overrides, form settings. A collection that exists on the source but not the target is created there (settings only — its objects don't travel). The environment-local counters (`count`, `totalObjects`) and the content timestamp (`lastUpdated`) **never** move: the receiving side always keeps its own, no matter what a payload carries.
- Objects from five reserved collections: `builder-pages`, `mailer`, `mcp-prompt`, `dataviews`, `automations`

> **Git-managed templates are excluded.** If you keep a `builder/` folder at your project root, templates travel by git, not Sync — so Sync skips them and carries schemas and allowlisted collection objects only. See [Git-First Templates](operations/git-first-templates).

### The collection allowlist

Sync moves objects for exactly those five collections and no others. The list is hardcoded, not a config option, and that is deliberate: the moment it becomes configurable, operators add collections holding images, files, galleries or depots and reasonably assume the binaries travel with them. Sync does not move binaries, and appearing to would be worse than refusing.

The practical consequence is that **your own custom collections' objects never sync.** A `products` or `comparisons` collection you defined holds content — it belongs to whichever server owns it. Sync carries the *schema* that defines it and the collection's *settings*, never the objects inside it.

## What Never Gets Synced

- Objects in custom collections, or in any reserved collection outside the five above
- Media files and images
- System settings
- API keys
- Reserved (built-in) schemas

## Setup

### 1. Create an API Key on the Production Server

On your **production** Total CMS instance:

1. Go to **Utilities > API Keys**
2. Create a new key with **GET** and **POST** permissions
3. Under endpoints, choose **Specific endpoints** and tick **Sync Manager** — this grants the `/sync` routes that push (`POST /sync/import`) and pull (`GET /sync/export`) use, and nothing else
4. Copy the generated API key

### 2. Configure Sync Settings

On your **local** Total CMS instance:

1. Go to **Settings > Sync**
2. Enter the production server's API URL (e.g., `https://example.com/tcms`)
3. Paste the API key from step 1
4. Save

## Using the Dashboard

Go to **Utilities > Sync Manager** to push or pull using the admin interface.

When the page opens it compares this site against the remote (the same comparison `--dry-run` shows in the CLI) and annotates every item:

- Unchanged items are dimmed — syncing them rewrites identical content
- **~ local newer / ~ remote newer** — the two copies differ, with a hint from their `updated` timestamps about which side holds the later edit
- **+ new** — exists here but not on the remote
- A per-section note counts items that exist only on the remote (a pull with **All** brings them down; a push leaves them untouched)

Selection works per section: **Select All**, individual checkboxes, or **Select Changed** — which checks exactly the items that differ or are missing on the remote, the usual "push my changes up" set. On a git-managed site the Templates section offers no checkboxes at all — it simply notes that templates travel by git and are excluded from sync.

**Push to Production** and **Pull from Production** open a preview before anything happens: what will be overwritten (with an explicit warning when the copy being replaced is the newer one), what will be created, and what's identical. If the remote can't be reached, the comparison is skipped and the page behaves as a plain picker with a standard confirmation.

## Using the CLI

The CLI provides `push` and `pull` commands that read the same sync settings from the dashboard.

### Push

```bash
# Full mirror: all schemas, templates, and allowlisted collection objects
tcms push

# Push specific schemas only — nothing else travels
tcms push --schemas=blog,products

# Push specific templates only
tcms push --templates=blog-post,sidebar

# Push the objects of specific allowlisted collections only
tcms push --collections=builder-pages,automations

# Push the SETTINGS of specific collections only (any collection)
tcms push --collection-meta=comparisons,builder-pages

# Combine filters
tcms push --schemas=blog --templates=blog-post

# Preview the full payload (objects included) without sending
tcms push --dry-run

# JSON output for scripting
tcms push --json
```

Filters are exclusive: as soon as any of `--schemas`, `--templates`, `--collections`, or `--collection-meta` is given, the categories you did not mention are left out entirely.

### Pull

```bash
# Full mirror down from production
tcms pull

# Pull specific items — nothing else travels
tcms pull --schemas=products
tcms pull --templates=blog-post,sidebar
tcms pull --collections=builder-pages
tcms pull --collection-meta=comparisons

# Preview without applying
tcms pull --dry-run

# JSON output
tcms pull --json
```

### Dry Run

Both `push` and `pull` support `--dry-run`, which compares both sides and shows what would actually **change** — not just what would travel.

```bash
tcms push --dry-run
```

```
Dry run — would push to https://example.com/tcms:

Schemas:
  ~ products      differs — local newer (local 29 Jul 2026 14:02, remote 24 Jul 2026 15:04)
  + invoice       new on remote
  = 6 unchanged: blog, events, faq, review, team, testimonial

Objects — builder-pages:
  ~ home          differs — remote newer (…) ← would overwrite the newer copy
  = 10 unchanged: about, blog, contact, …
  · 2 only on remote — untouched (push never deletes)
```

How to read it:

- `~` — the two copies differ. Content is compared by hash with timestamps excluded, so two identical copies with different save dates read as unchanged.
- `+` — exists only on the sending side; the sync creates it on the target.
- `=` — identical on both sides. The sync still writes them, but nothing changes.
- `·` — exists only on the receiving side. Sync never deletes, so these are left alone.

When copies differ, the `updated` timestamps say **which side holds the newer edit** — and the preview warns explicitly when the sync would land an older copy on top of a newer one. Two caveats: an item with a timestamp on only one side reports that side as *likely* newer (a copy without one was last written by a release that didn't maintain the field, which usually — not always — means it's older), and the comparison trusts each machine's clock. Treat direction as a hint; the differs/unchanged status itself doesn't depend on timestamps.

If the remote can't be reached, the preview degrades to a plain listing of the payload without comparison.

## How It Works

Sync is built on top of Total CMS's JumpStart system. When you push:

1. The local instance exports the selected schemas, templates and allowlisted collection objects as a JumpStart payload
2. The payload is sent to the production server's `/api/sync/import` endpoint
3. The production server imports it, replacing any existing versions

When you pull, the process is reversed — the production server exports, and the local instance imports.

Step 2 uses `/api/sync/import` rather than the general `/api/import/jumpstart` route, and the difference matters. The general import route is built for starter kits, so it *skips* anything that already exists. The sync route runs the importer in **upsert** mode instead, so a push lands as a true mirror of the source rather than silently ignoring every record the target already has.

## Overwrite Behavior

Sync always overwrites on the target. If a schema, template or object with the same ID already exists there, it is replaced with the synced version. This is intentional — sync deploys known changes, it does not merge them.

> **A bare `tcms push` is a full mirror — it includes objects.** With no filter flags, a push carries every custom schema, every template, and every object in all five allowlisted collections. If pages are edited on the production server, push from local with that in mind. To move one thing, name it: the moment any filter flag is given, the categories you did not mention are excluded — `tcms push --schemas=blog` pushes the blog schema and nothing else. `--dry-run` shows the complete payload, objects included.

## Automatic Backups

Before a sync overwrite replaces an existing schema or object, the instance being overwritten snapshots its current version to:

```
tcms-data/.system/backups/schemas/{id}/{id}-{YYYYMMDD-HHMMSS}.json
tcms-data/.system/backups/objects/{collection}/{id}/{id}-{YYYYMMDD-HHMMSS}.json
tcms-data/.system/backups/collections/{id}/{id}-{YYYYMMDD-HHMMSS}.json
```

This happens on whichever side is receiving: production backs up on a push, your local instance backs up on a pull. Each schema and object keeps its ten most recent snapshots; re-syncing unchanged content does not stack duplicates. Restoring is a manual copy — find the snapshot you want and copy it back over the live file, then clear the cache.

Backups only cover what sync overwrites. They are not a substitute for real backups of `tcms-data/`.

## Timestamps

Schemas carry a top-level `updated` value stamped on every save, and each of the five syncable collections has an auto-maintained `updated` field. These exist so dry-run can tell you which side of a difference holds the newer edit.

Collections carry **two** timestamps with deliberately different meanings:

- `lastUpdated` — the **content** timestamp. Bumped by every object create, update, and delete. Says nothing about settings.
- `updated` — the **settings** timestamp. Stamped only when the collection's configuration actually changes (the save compares the config before writing, so content activity and counter bumps never move it). This is the value sync freshness hints compare.


The rule that makes them trustworthy: **a sync import preserves incoming timestamps instead of restamping them.** A synced copy keeps the save date of the original it mirrors — a timestamp only moves when a person (or the API) actually edits the item on that machine. Without this rule every sync would make the receiving side look newer than the sender, and the comparison would be permanently wrong in one direction.

Items saved before this feature existed have no timestamp yet; they gain one on their next save.

## Alternative: Git / Source Control

If your `tcms-data/` directory is tracked in version control, git itself is an effective way to keep schemas and templates in sync between environments. Schemas live in `tcms-data/.schemas/` as JSON files, and templates live in `tcms-data/builder/` as Twig files — both are plain text and diff cleanly.

A typical git-based workflow:

1. Make schema and template changes locally
2. Commit to your branch
3. Push to remote and deploy to production
4. Run `tcms cache:clear` on production after deployment

This approach works well for teams that already have a git-based deployment pipeline. The built-in Sync feature is designed for workflows where direct file access to the production server isn't available — for example, when T3 is hosted on a managed server and you only have access through the admin dashboard and API.

Both approaches can coexist. Use git for your primary deployment workflow, and Sync for quick one-off pushes when you need to update a schema without a full deployment.

## Troubleshooting

**"Sync not configured"** — Set the production URL and API key in Settings > Sync.

**Push fails with HTTP 401** — The API key is invalid or doesn't have the required permissions. Verify the key on the production server has GET and POST access to `/export/*` and `/import/*`.

**Push fails with HTTP 404** — The production server URL may be incorrect. Make sure it points to the Total CMS API root (e.g., `https://example.com/tcms`), not the site root.

**Connection timeout** — The production server may be unreachable from your local environment. Check network connectivity and firewall rules.
