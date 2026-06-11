---
name: totalcms
description: Use when building, editing, or managing a Total CMS (T3) site — creating Site Builder pages, working with collections, schemas, or objects, using the tcms CLI, or setting up the frontend/Vite pipeline. Covers the local build workflow end to end.
---

# Building a Total CMS (T3) site

This project **is a website** built on Total CMS, a flat-file PHP CMS. There is
**no database** — all content is JSON under `tcms-data/`. The CMS core is installed
by Composer into `vendor/totalcms/cms/`; **never edit `vendor/`** (it is replaced on
update). Configure via `config/tcms.php` (deep-merged — specify only keys you change).

The CLI is `vendor/bin/tcms`. Most commands accept `--json` for machine-readable
output — prefer it when scripting. Run `vendor/bin/tcms list` to see everything.

## Where to look things up

You do **not** need to memorize field options or Twig signatures — they ship on disk:

- **On-disk docs (always present):** `vendor/totalcms/cms/resources/docs/<section>/`
  (`menu.php` is the table of contents, `search-index.json` a prebuilt index).
  Sections include `site-builder/`, `collections/`, `schemas/`, `fields/`, `twig/`,
  `forms/`, `apis/`, `extensions/`, `operations/`. Grep or read these for the long tail.
- **MCP docs server (optional accelerator):** if `mcp.totalcms.co` is connected,
  use `docs_search`, `docs_twig_function`, `docs_field_type`, `docs_cli_command`, etc.
  It is faster but not required — the on-disk docs are authoritative.

Prefer looking things up over guessing; training data is often stale on exact signatures.

## The build loop: add a page to the site

1. **See what already routes:** `vendor/bin/tcms builder:routes` lists every page
   the router serves and flags conflicts. `vendor/bin/tcms builder:routes --json` to script.
2. **If Site Builder isn't set up yet,** scaffold a starter:
   `vendor/bin/tcms builder:init <starter>` where `<starter>` is `business`, `blog`,
   `portfolio`, or `minimal`. Add `--frontend` to also install the Vite pipeline.
   This copies templates, ensures the `builder-pages` collection, and seeds demo pages.
3. **Edit templates** on the filesystem under
   `tcms-data/builder/{layouts,pages,partials,macros}/*.twig`. Twig global is `cms`;
   builder helpers are `cms.builder.nav()`, `cms.builder.url(id, params)`,
   `cms.builder.css/js/asset()`. See `references/site-builder.md`.
4. **Add a page record.** A page is an object in the `builder-pages` collection.
   **There is no `object:create` CLI command** — create content one of three ways:
   - the **admin UI** (Site Builder → Pages), or
   - `vendor/bin/tcms collection:import builder-pages <file.json>` (see `references/cli.md`), or
   - `vendor/bin/tcms jumpstart:import <file>` for bulk seeding.
   Key `builder-pages` fields: `route`, `template`, `title`, `draft`, `data` (free-form
   JSON exposed as `page.data.*`). Full list in `references/site-builder.md`.
5. **Preview:** serve `public/` (`php -S localhost:8080 -t public`) and visit the
   page's `route`. Clear caches after template changes if needed:
   `vendor/bin/tcms cache:clear`.

## Reference files (read on demand)

| When you are… | Read |
|---|---|
| running any `tcms` command / scripting with `--json` | `references/cli.md` |
| editing builder templates, routes, or page records | `references/site-builder.md` |
| setting up or building frontend assets | `references/frontend.md` |
| modeling data — collections, schemas, objects | `references/data-model.md` |
| needing an exact field option / Twig signature | `vendor/totalcms/cms/resources/docs/<section>/` (or MCP) |
