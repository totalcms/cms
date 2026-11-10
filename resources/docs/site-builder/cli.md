---
title: "Builder CLI Commands"
description: "Site Builder CLI reference: scaffold sites from starter templates, install a frontend pipeline, list routes, and manage template history."
since: "3.5.0"
---

# Builder CLI Commands

The Site Builder provides five CLI commands: scaffolding from starters, installing a frontend asset pipeline, inspecting registered routes, managing template version history, and exporting a finished site as a starter.

| Command | Purpose |
|---------|---------|
| [`builder:init`](#builder-init) | Scaffold a new site from a starter template |
| [`builder:frontend`](#builder-frontend) | Install and build the Vite asset pipeline at the project root |
| [`builder:routes`](#builder-routes) | List every route the page router would serve, with conflicts flagged |
| [`builder:history`](#builder-history) | List, view, or restore template snapshot versions |
| [`starter:export`](#starter-export) | Write a clean copy of the site as a Composer starter project |

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
4. (with `--frontend`) installs and builds the Vite pipeline — same as running `tcms builder:frontend`

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `starter` | No | Starter template name. Omit to list available starters. |

### Options

| Option | Description |
|--------|-------------|
| `--list, -l` | List available starters and exit |
| `--force, -f` | Overwrite existing template files (existing page records in the collection are left alone — JumpStart skips objects that already exist) |
| `--frontend` | Also install and build the Vite pipeline (equivalent to running `tcms builder:frontend`) |
| `--no-build` | With `--frontend`: install the files, but skip `npm install` and the build |
| `--json` | Output result as JSON |

### Starter Data

Every starter includes a `jumpstart.json` next to its `manifest.json`. The CLI runs it through the JumpStart importer after templates are copied so pages and any seed content land in the right places. For the **blog** starter that means 5 pages + 5 sample posts; for **business** that means 5 pages + a `service` schema + a `services` collection + 4 sample services.

If the import fails (e.g., schema validation), the scaffold itself still succeeds — templates are already on disk, and you can re-run the import manually with `tcms jumpstart:import resources/builder/starters/<name>/jumpstart.json`.

### `--frontend` Behavior

Convenience flag for the greenfield happy path. It puts the starter's stylesheet in `css/style.css` at the project root, then runs `tcms builder:frontend`, which keeps it and builds it. If the project can't take the pipeline, the command stops before it scaffolds anything.

Without `--frontend`, the stylesheet goes to `public/assets/css/style.css`, and the same layout line loads it. You can add the pipeline later — see [`builder:frontend`](#builder-frontend). Move the stylesheet to `css/style.css` first, because the first build would delete it.

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

Install the Vite asset pipeline at your project root, then build it. It's for projects with no `package.json`, and it stops if it finds one, so it never edits a setup you already have.

```bash
tcms builder:frontend
tcms builder:frontend --no-build
tcms builder:frontend --dry-run
tcms builder:frontend --json
```

### What It Does

Installs the contents of `resources/builder/frontend/` at the project root:

- `package.json` — `dev` / `build` / `watch` / `preview` scripts, with Vite as the only package
- `vite.config.js` — builds `css/` and `js/` into `public/assets/` with a manifest
- `css/style.css` — minimal starter stylesheet
- `js/app.js` — minimal entry point
- `FRONTEND.md` — quick reference for the install and build workflow

It then:

1. Adds `/node_modules/` and `/public/assets/` to `.gitignore`.
2. Adds the stylesheet to `layouts/default.twig`, unless the layout already loads one.
3. Keeps a marked block about the pipeline in `AGENTS.md`, if the project has one.
4. Runs `npm install` and the build, then clears the cache.

T3's `cms.builder.css()` and `cms.builder.js()` Twig helpers resolve the built files through the manifest.

### Options

| Option | Description |
|--------|-------------|
| `--no-build` | Install the files, but skip `npm install` and the build |
| `--dry-run` | Show what would change, and change nothing |
| `--json` | Output result as JSON |

### When It Refuses

All checks run before the first write, so a refused run changes nothing. The command refuses when:

- `public/assets/` holds files with no `manifest.json`, because the first build would delete them. It lists the files
- The project has a `frontend/` pipeline. See [Moving from frontend/](docs/site-builder/frontend#moving-from-frontend)
- Total CMS isn't at `public/index.php`. A subpath install leaves `public/` for your own frontend
- The project already has a `package.json`. That includes running the command a second time. See [Adding Vite to an Existing Project](docs/site-builder/frontend#adding-vite-to-an-existing-project)

### Existing Files

- **`vite.config.js`** — kept, with a warning if it doesn't set `publicDir` or `manifest`
- **`css/`, `js/` and `FRONTEND.md`** — kept

### Without Node

The build needs Node 20.19+ or 22.12+. Without a supported version, the command installs the files, explains, and exits 0. Build on any machine with Node and upload `public/assets/`. A build that fails exits 1, and the files stay in place.

### When to Use This vs. `--frontend` on `builder:init`

| Scenario | Use |
|----------|-----|
| Greenfield project, scaffolding for the first time | `tcms builder:init <starter> --frontend` |
| Existing project, adding a frontend pipeline now | `tcms builder:frontend` |
| Project already has a `package.json` | Add Vite by hand. See [Adding Vite to an Existing Project](docs/site-builder/frontend#adding-vite-to-an-existing-project) |

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

## `starter:export`

Write a clean copy of the current site as a Composer starter project: a complete site that others install with `composer create-project`.

```bash
tcms starter:export ~/Developer/starter-restaurant --name=totalcms/starter-restaurant
tcms starter:export ../starter --structure-only
```

The command only reads the running site. It writes the copy to a folder outside the project, which must be empty unless you pass `--force`.

### Arguments and Options

| Argument / option | Description |
|---|---|
| `dir` | Folder to write the starter to. Must be outside this project. |
| `--name` | Composer package name. Defaults to `totalcms/starter-<folder name>`. |
| `--description` | Composer package description. |
| `--structure-only` | Leave out every object except the Site Builder pages. Schemas, collections, pages and templates are kept. |
| `--yes`, `-y` | Include the content without asking. |
| `--force`, `-f` | Write into a folder that is not empty. |

Without `--yes` or `--structure-only`, the command lists the collections that hold content and asks whether to include them. A site built for one client is the usual source, and its content belongs to that client.

### What Is Copied

- The project, minus `vendor/`, `cache/`, `tmp/`, `logs/`, `composer.lock`, `.git/`, `node_modules/` and `.cache/` image folders.
- `tcms-data/` at the project root, with every collection, schema, page and object.

To leave out more, such as design notes, seed files or backups of the site, list them in a `.starterignore` file at the project root. Each line names a path from the project root, and `*` matches within one folder. Lines starting with `#` are comments:

```text
# Kept for building the site, not part of the starter
snapshots/
seed/
bin/video-*.sh
```

The `.starterignore` file itself is never copied.
- From `tcms-data/.system/`, only the settings, extension state and settings, access groups and the migration record. Everything else there is runtime state or a credential: the site key, API keys, OAuth keys and clients, sessions, logs, backups, the job queue and the setup state.

### What Is Changed

- **Users are left out.** Collections using the `auth` schema are copied empty, so the setup wizard runs for whoever installs the starter and creates their admin account.
- **Secrets are blanked.** Every settings value whose field type is `password` or `secret` (SMTP password, sync key, extension keys) is emptied, and so is any value holding this project's path.
- **IndexNow is turned off and its key left out** of the Site SEO record, so sites built from the starter don't share one. A site makes its own key when IndexNow is turned on.
- **`config/tcms.php` is replaced** with a blank one, because it can hold credentials and machine-specific paths. The command lists the keys your file set, so you can move what the starter needs into the admin's Settings.
- **`composer.json`** gets the new name and description, the `totalcms-starter` keyword, `totalcms/cms` pinned to the running minor version (`^3.6`), and a post-install script that prints next steps.
- **`.gitignore`** tracks `tcms-data/` (the plain skeleton ignores it) but leaves out runtime files.
- **`README.md`** is written from a template unless you already wrote your own. `CREDITS.md` lists every image that has a [credit](docs/fields/image-gallery), grouped by source, and the report names the images that don't. A `CREDITS.md` you wrote yourself is kept.

### Final Check

The command then searches every output file for each secret the running site holds: the site key, API keys, OAuth client secrets, the cron token and sensitive settings values. If one turns up, the command lists the files and exits with an error. Don't publish the output until it passes.

It also prints the largest files. Images are not resized for you: resize them in the site before exporting.

## See Also

- [Site Builder Overview](docs/site-builder/overview)
- [Builder Admin UI](docs/site-builder/admin)
- [Starter Templates](docs/site-builder/starters)
