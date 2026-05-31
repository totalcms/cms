---
title: "Git-First Templates"
description: "Source-control your Site Builder templates and deploy them with git. Create a builder/ folder at the project root and templates become version-controlled, the admin editor goes read-only, and the repo is the single source of truth."
since: "3.5.0"
related:
  - operations/deployment
  - operations/sync
---

# Git-First Templates

By default, Site Builder templates live in `tcms-data/builder/` and you edit them in the dashboard. That's perfect for content-led sites, but developers usually want their templates in **git** — edited in an IDE, reviewed in pull requests, and deployed like any other code.

Total CMS supports this with **zero configuration**. If a `builder/` folder exists at your **project root**, templates are git-managed; if it doesn't, nothing changes. This mirrors how Total CMS already detects `tcms-data` — by directory presence, not a setting.

## Turning it on

Move your builder templates to the project root (alongside `public/`, `config/`, `frontend/`):

```bash
mv tcms-data/builder ./builder
```

That's the entire switch. The folder's presence is what activates git-managed mode. (A fresh project can instead run `tcms builder:init <starter>`, which scaffolds straight into `./builder` when it exists, or your `composer create-project` setup can create it for you.)

Now commit `./builder` — there's nothing special to ignore. `tcms-data/` stays gitignored as always (your content and media are not in git), and edit-history snapshots live under `tcms-data/`, never in `./builder` — so the whole `./builder` folder is clean to commit.

## What changes when templates are git-managed

| | Admin-first (default) | Git-managed (`./builder` exists) |
|---|---|---|
| Templates live in | `tcms-data/builder/` | `./builder/` (committed) |
| Editing | In the dashboard | In your IDE → commit → deploy |
| Dashboard editor | Editable | **Read-only** ("Editing Disabled") in every environment |
| Template sync | Via the Sync Manager | **Disabled** — templates travel by git |

### Read hierarchy

Templates resolve in this order (first match wins):

1. `./builder/` — your committed templates
2. `tcms-data/builder/` — anything edited in the dashboard (legacy / leftover)
3. Total CMS's built-in defaults (e.g. `layouts/default.twig`) — the floor, so a bare builder still renders

So your committed templates always win, and you can extend the shipped `layouts/default.twig` without copying it.

### The dashboard goes read-only

Once `./builder` exists, the template and builder editors show an **"Editing Disabled"** notice and hide Save/Delete — in *every* environment, including local dev. This is deliberate: the repo is the single source of truth, so there's never a second, conflicting way to change a template. Edit the files in your editor and reload to preview.

## Deploying

Because templates are committed and the dashboard can't write them, your production working tree stays clean — a deploy webhook's `git pull` never hits an uncommitted-changes conflict:

```bash
cd /var/www/your-site
git pull          # brings in template changes
# clear caches as usual — see the Deployment guide
```

See the [Deployment guide](operations/deployment) for webhook and cache-clearing details.

## Pages and content still use Sync

Git-first templates cover **templates only**. Your **pages** (the `builder-pages` records: routes, template bindings, nav, content) and other collection data are not git-controlled — they're promoted local → production with the [Sync Manager](operations/sync), exactly as before. Each artifact has one delivery channel:

- **Templates → git**
- **Pages & content → Sync**

So a fresh `git clone` brings up your templates; running a sync brings the pages and content over.

## Going back

Delete (or move back) the `./builder` folder and the site returns to admin-first: templates live in `tcms-data/builder/` and are editable in the dashboard again. There's no setting to flip.
