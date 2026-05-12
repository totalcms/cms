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

## What Never Gets Synced

- Collection content and objects
- Media files and images
- System settings
- API keys
- Reserved (built-in) schemas

## Setup

### 1. Create an API Key on the Production Server

On your **production** Total CMS instance:

1. Go to **Utilities > API Keys**
2. Create a new key with **GET** and **POST** permissions
3. Set the endpoint pattern to `/export/*` and `/import/*`
4. Copy the generated API key

### 2. Configure Sync Settings

On your **local** Total CMS instance:

1. Go to **Settings > Sync**
2. Enter the production server's API URL (e.g., `https://example.com/tcms`)
3. Paste the API key from step 1
4. Save

## Using the Dashboard

Go to **Utilities > Sync Manager** to push or pull using the admin interface.

- **Select All** syncs every custom schema and template
- Uncheck **Select All** to pick individual schemas and templates
- Click **Push to Production** to send your local changes to the remote server
- Click **Pull from Production** to download remote schemas and templates to your local instance
- Both actions show a confirmation dialog before proceeding

## Using the CLI

The CLI provides `push` and `pull` commands that read the same sync settings from the dashboard.

### Push

```bash
# Push all schemas and templates
tcms push

# Push specific schemas only
tcms push --schemas=blog,products

# Push specific templates only
tcms push --templates=blog-post,sidebar

# Combine filters
tcms push --schemas=blog --templates=blog-post

# Preview without sending
tcms push --dry-run

# JSON output for scripting
tcms push --json
```

### Pull

```bash
# Pull all schemas and templates
tcms pull

# Pull specific items
tcms pull --schemas=products
tcms pull --templates=blog-post,sidebar

# Preview without applying
tcms pull --dry-run

# JSON output
tcms pull --json
```

### Dry Run

Both `push` and `pull` support `--dry-run` which shows what would be synced without making any changes. This is useful for verifying your filters before running the actual sync.

```bash
tcms push --dry-run
```

```
Dry run — would push to https://example.com/tcms:

Schemas:
  - products
  - invoice
Templates:
  - blog-post
  - sidebar
```

## How It Works

Sync is built on top of Total CMS's JumpStart system. When you push:

1. The local instance exports selected schemas and templates as a JumpStart payload
2. The payload is sent to the production server's `/import/jumpstart` endpoint
3. The production server imports the schemas and templates, overwriting any existing versions

When you pull, the process is reversed — the production server exports its schemas and templates, and the local instance imports them.

## Overwrite Behavior

Sync always overwrites existing schemas and templates on the target. If a schema or template with the same ID already exists, it will be replaced with the synced version. This is intentional — sync is designed for deploying known changes, not merging.

## Alternative: Git / Source Control

If your `tcms-data/` directory is tracked in version control, git itself is an effective way to keep schemas and templates in sync between environments. Schemas live in `tcms-data/.schemas/` as JSON files, and templates live in `tcms-data/templates/` as Twig files — both are plain text and diff cleanly.

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
