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
   `builder/{layouts,pages,partials,macros}/*.twig` when a project-root `builder/`
   directory exists (git-managed mode: it wins over `tcms-data/`, and the admin's
   template editor is read-only), otherwise `tcms-data/builder/`. Twig global is `cms`;
   builder helpers are `cms.builder.nav()`, `cms.builder.url(id, params)`,
   `cms.builder.css/js/asset()`. See `references/site-builder.md`.
4. **Add a page record.** A page is an object in the `builder-pages` collection.
   Create it one of these ways:
   - `echo '{"id":"about","title":"About","route":"/about","template":"pages/page.twig"}' | vendor/bin/tcms object:create builder-pages -` (one object, file or stdin), or
   - the **admin UI** (Site Builder → Pages), or
   - `vendor/bin/tcms collection:import builder-pages <file.json>` for arrays (see `references/cli.md`), or
   - `vendor/bin/tcms jumpstart:import <file>` for bulk seeding.
   Key `builder-pages` fields: `route`, `template`, `title`, `draft`, `data` (free-form
   JSON exposed as `page.data.*`). Full list in `references/site-builder.md`.
5. **Preview:** `php -S localhost:8080 -t public public/index.php` and visit the
   page's `route`. Pass `public/index.php` as the router script — without it the
   built-in server only serves files that exist on disk and never reaches the page
   router, so every builder page 404s. Clear caches after template changes if
   needed: `vendor/bin/tcms cache:clear`. Restart the server after adding page
   records — the route index is cached in the server process.

## Writing schemas and content by hand

The admin enforces these for you. They only bite when you author JSON directly —
JumpStart files, imports, the API — which is exactly what an agent tends to do.

- **Every schema must define an `id` property**, not just list `id` in `required`.
  A schema missing it fails with `The required properties (id) are missing`, which
  points at your data when the bug is in the schema.
- **Decks are dictionaries of named items, not arrays.** Each key names one item and
  must equal that item's `id`:
  `{"rows": {"first_row": {"id": "first_row", ...}}}`
- **Deck item keys allow only letters, numbers, and underscores** — no hyphens, so
  Twig can read them as `deck.first_row`. `first-row` is rejected on import.
- **`field: "deckTable"`** stores exactly like a deck but edits as a table.

See `vendor/totalcms/cms/resources/docs/fields/deck.md` for the full shape.

**After editing any schema JSON in place, run `vendor/bin/tcms schema:lint <id>`**
— imports validate on the way in, but in-place edits are never re-checked, and
the linter also flags properties missing the help text AI agents rely on.

## Working against a live site

Two rules prevent the two worst mistakes:

- **Patch, don't replace.** When editing a live site through its MCP server,
  prefer `patch_object` (merges only the fields you send). `update_object` is a
  FULL replace — if you must use it, fetch the complete record with
  `get_object` + `format: "html"` first, edit, strip the decorated `url` key,
  and write the whole body back. Details: `references/mcp-content.md`.
- **After launch, the server owns content.** Local seed files go stale by
  design — never re-import them over live data. Use `pull`/`push` (dry-run
  first; filters are exclusive). Details: `references/going-live.md`.

## Reference files (read on demand)

| When you are… | Read |
|---|---|
| running any `tcms` command / scripting with `--json` | `references/cli.md` |
| editing builder templates, routes, or page records | `references/site-builder.md` |
| setting up or building frontend assets | `references/frontend.md` |
| modeling data — collections, schemas, objects | `references/data-model.md` |
| reading/writing content via the site's MCP server | `references/mcp-content.md` |
| launching, syncing with production (`push`/`pull`) | `references/going-live.md` |
| needing an exact field option / Twig signature | `vendor/totalcms/cms/resources/docs/<section>/` (or MCP) |
