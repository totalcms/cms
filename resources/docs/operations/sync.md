---
title: "Sync"
description: "Push and pull schemas and templates between local development and production Total CMS instances using the CLI or admin dashboard."
since: "3.5.0"
---

# Sync

Sync lets you push schemas and templates from a local development instance to a production server, or pull them from production to your local environment. This enables a proper development workflow where you build and test locally, then deploy structural changes to production without touching content or media.

## Breaking changes in 3.5.1

3.5.1 renames the sync flags and changes what one of them means:

- **`--collections` now means collection SETTINGS**, not objects. Before 3.5.1 it moved objects from five allowlisted collections; that meaning is gone.
- **The flag formerly named `collection-meta` is removed.** Its job — pushing/pulling collection settings — is now `--collections`.
- Objects for the five site-machinery collections move via their own feature flags instead: `--pages`, `--dataviews`, `--mailer`, `--mcp-prompts`, `--automations`.
- **New:** `push --objects=collection[:id,id]` seeds object data from any seedable collection to production. Unlike everything else in Sync, it never overwrites — existing objects on the target are skipped unless you add `--overwrite`. It is push-only; `pull` has no equivalent.

If you have scripts or CI steps calling `tcms push --collections=...` expecting objects, or the old collection-settings flag, update them before upgrading.

## What Gets Synced

- Custom schemas (`.schemas/` directory)
- Custom templates
- **Collection settings** — every collection's configuration (`.meta.json`): URL, MCP card, sitemap settings, access groups, schema overrides, form settings. Moved with `--collections` (any collection, not just the five below). A collection that exists on the source but not the target is created there (settings only — its objects don't travel). The environment-local counters (`count`, `totalObjects`) and the content timestamp (`lastUpdated`) **never** move: the receiving side always keeps its own, no matter what a payload carries.
- **Site-machinery objects**, one feature flag per collection — `--pages` (`builder-pages`), `--dataviews` (`dataviews`), `--mailer` (`mailer`), `--mcp-prompts` (`mcp-prompt`), `--automations` (`automations`). Each upserts: given with no value it moves every object in that collection (`--pages`), given a value it moves just those ids (`--pages=home,about`).
- **Seeded objects (push only)** — `--objects=collection[:id,id]` exports object data from any seedable collection and lands it on the target, but only where the target doesn't already have that id. See [Seeding objects with `--objects`](#seeding-objects-with-objects) below.

> **Git-managed templates are excluded.** If you keep a `builder/` folder at your project root, templates travel by git, not Sync — so Sync skips them and carries schemas and the flagged/settings/seeded data only. See [Git-First Templates](operations/git-first-templates).

### The five feature flags

`--pages`, `--dataviews`, `--mailer`, `--mcp-prompts`, and `--automations` each move one reserved collection's objects, always in upsert mode (same as schemas and templates — the target's copy is replaced). They're named for the admin feature, not the storage collection, so you don't have to know that Pages live in a collection called `builder-pages`.

These five are the only collections sync can move *destructively* (overwriting an existing object on the target). Every other collection's objects only ever arrive via `--objects`, which is non-destructive by default — see below.

### Seeding objects with `--objects`

`push --objects=collection` (or `collection:id,id` for specific objects, repeatable) exports object data from any **seedable** collection and imports it on the target through the skip-existing endpoint: an object the target already has by that id is left untouched. This is seeding, not mirroring — it's for landing starter/demo content on a fresh production site, not for keeping two live sites in sync.

- Add `--overwrite` to let the local copy win over an existing object on the target instead of being skipped. Combined with `--objects`, this is the only irreversible thing `push` can do, so **`--objects` with `--overwrite` always requires `--force`** — in a terminal and in CI alike. Run `tcms push --objects=... --overwrite --dry-run` first to see what would change (a dry run never pushes, so it needs no `--force`), then re-run with `--force`. `--overwrite` on its own, with no `--objects`, is a no-op and is not guarded: everything else upserts either way.
- A push that mixes seeded objects with anything else — `push --schemas=faq --objects=faq` — sends **two** requests: the schemas, templates, feature-flag objects and collection settings upsert through `/api/sync/import`, then the seeded objects alone go to the skip-existing `/api/import/jumpstart`. Each half keeps the semantics its flag documents. The mirror half goes first (so a new schema or collection exists before its rows land); if it fails, the seed is not sent and the command says so, and it exits non-zero if either half fails.
- `--objects` and `--overwrite` are **push-only** — `pull` has no seeding mode.
- Not every collection is seedable:
  - The five feature-flag collections (`builder-pages`, `dataviews`, `mailer`, `mcp-prompt`, `automations`) are reachable only through their own flag, not `--objects`.
  - `image`, `gallery`, `file`, and `depot` can never be seeded — see [Binaries never travel](#what-never-gets-synced) below.
  - `playground` can never be seeded — it's a per-install scratchpad created on demand, not portable content.
- Binaries never travel even for seedable collections: a card or field that references an image/file/gallery/depot is exported without that field, so the receiving object simply won't have it populated. A pushed blog post never blanks a production image field, because the field never arrives at all.

## What Never Gets Synced

- Objects in custom collections you haven't explicitly moved with `--objects`
- `image`, `gallery`, `file`, and `depot` collections — cannot be seeded at all (see above); and image/file/gallery/depot **fields** on any object are always stripped from every payload, seeded or not
- `playground` — never syncs, never seeds
- Media files and images
- System settings
- API keys
- Reserved (built-in) schemas

## The Source-of-Truth Rule

Sync is a mirror, and mirrors need a direction. The rule that keeps the two
sides sane: **before launch, your local project is the source of truth. After
launch, the production server is.**

Before launch, everything — content, schemas, templates, settings — is authored
locally and deployed together. Seed files and imports are fine; you can wipe and
re-seed at will.

The day the site goes live, content ownership flips. Editors, forms, and AI
agents write to production, and any local copy of *content* is stale the moment
they do. From then on:

- **Content** is production's. Work with it by pulling it down, or by editing
  production directly (admin, REST, MCP). Never re-import old local seed files
  over live data — delete them once the site is live so they can't be reached
  for by accident.
- **Schemas and collection settings** remain yours to develop locally — that is
  what `push --schemas` / `push --collections` are for. A dry run showing the
  remote side "likely newer" means someone changed it in production: pull it
  before you push over it.
- **Templates** follow whichever mode you chose: git-managed (a project-root
  `builder/` folder) deploys through git; otherwise they sync like schemas.

The dry run is how you check the direction assumption before every sync — it
costs one command and has caught every would-be overwrite so far.

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
# Full mirror: all schemas, templates, feature-flagged objects, and settings
tcms push

# Push specific schemas only — nothing else travels
tcms push --schemas=blog,products

# Push specific templates only
tcms push --templates=blog-post,sidebar

# Push all Site Builder pages
tcms push --pages

# Push just two pages
tcms push --pages=home,about

# Push the SETTINGS of specific collections only (any collection)
tcms push --collections=comparisons,builder-pages

# Seed blog objects to a fresh production site — existing posts are skipped
tcms push --objects=blog

# Seed two specific objects, and let this run overwrite any that already exist
# (--overwrite alongside --objects always needs --force; dry-run first to see what changes)
tcms push --objects=blog:launch-day,q3-roadmap --overwrite --dry-run
tcms push --objects=blog:launch-day,q3-roadmap --overwrite --force

# Combine filters
tcms push --schemas=blog --templates=blog-post

# Preview the full payload without sending
tcms push --dry-run

# JSON output for scripting
tcms push --json
```

Filters are exclusive: as soon as any of `--schemas`, `--templates`, `--pages`, `--dataviews`, `--mailer`, `--mcp-prompts`, `--automations`, `--collections`, or `--objects` is given, the categories you did not mention are left out entirely.

`--objects` and `--overwrite` are push-only — seeding content onto production has no pull equivalent.

### Pull

```bash
# Full mirror down from production
tcms pull

# Pull specific items — nothing else travels
tcms pull --schemas=products
tcms pull --templates=blog-post,sidebar
tcms pull --pages=home,about
tcms pull --collections=comparisons

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

Pages:
  ~ home          differs — remote newer (…) ← would overwrite the newer copy
  = 10 unchanged: about, blog, contact, …
  · 2 only on remote — untouched (push never deletes)
```

`--dry-run` also previews a `--objects` seed, under its own "Seeded objects" section listing what's new on the target — a seed never overwrites, so it has no `~`/`=`/`·` rows of its own.

How to read it:

- `~` — the two copies differ. Content is compared by hash with timestamps excluded, so two identical copies with different save dates read as unchanged.
- `+` — exists only on the sending side; the sync creates it on the target.
- `=` — identical on both sides. The sync still writes them, but nothing changes.
- `·` — exists only on the receiving side. Sync never deletes, so these are left alone.

When copies differ, the `updated` timestamps say **which side holds the newer edit** — and the preview warns explicitly when the sync would land an older copy on top of a newer one. Two caveats: an item with a timestamp on only one side reports that side as *likely* newer (a copy without one was last written by a release that didn't maintain the field, which usually — not always — means it's older), and the comparison trusts each machine's clock. Treat direction as a hint; the differs/unchanged status itself doesn't depend on timestamps.

If the remote can't be reached, the preview degrades to a plain listing of the payload without comparison.

## How It Works

Sync is built on top of Total CMS's JumpStart system. When you push:

1. The local instance exports the selected schemas, templates, feature-flagged objects and collection settings as a JumpStart payload
2. The payload is sent to the production server's `/api/sync/import` endpoint
3. The production server imports it, replacing any existing versions

When you pull, the process is reversed — the production server exports, and the local instance imports.

Step 2 uses `/api/sync/import` rather than the general `/api/import/jumpstart` route, and the difference matters. The general import route is built for starter kits, so it *skips* anything that already exists. The sync route runs the importer in **upsert** mode instead, so a push lands as a true mirror of the source rather than silently ignoring every record the target already has.

`push --objects` is the one exception: the seeded objects route through the skip-existing import path instead (the same one starter kits use), which is what makes them a seed rather than a mirror. `--overwrite` switches them to the same upsert path everything else uses.

The two modes are per-item, not per-push. A push that seeds objects *and* carries anything else splits into two requests — the upserting half to `/api/sync/import`, the seeded objects to `/api/import/jumpstart` — so naming `--objects` never quietly downgrades your schemas, pages or collection settings to skip-existing. The upserting half is sent first, and if it fails the seed is not sent at all.

## Overwrite Behavior

Everything except a plain `--objects` seed overwrites on the target: if a schema, template, page/dataview/mailer/mcp-prompt/automation object, or collection's settings already exists there under the same ID, it is replaced with the synced version. This is intentional — sync deploys known changes, it does not merge them.

`--objects` is the exception, and deliberately so: existing objects on the target are left alone unless you add `--overwrite`, because seeding a fresh site with starter content should never clobber something an editor already changed in production.

> **A bare `tcms push` is a full mirror.** With no filter flags, a push carries every custom schema, every template, every object in the five feature-flagged collections, and every collection's settings — but never seeds `--objects`, which only ever runs when named explicitly. If pages are edited on the production server, push from local with that in mind. To move one thing, name it: the moment any filter flag is given, the categories you did not mention are excluded — `tcms push --schemas=blog` pushes the blog schema and nothing else. `--dry-run` shows the complete payload.

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

Schemas carry a top-level `updated` value stamped on every save, and each of the five feature-flagged collections (`builder-pages`, `dataviews`, `mailer`, `mcp-prompt`, `automations`) has an auto-maintained `updated` field. These exist so dry-run can tell you which side of a difference holds the newer edit.

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
