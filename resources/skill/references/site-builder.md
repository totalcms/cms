# Site Builder reference

> This is a summary. For exhaustive detail, read the on-disk docs that ship with
> the package at `vendor/totalcms/cms/resources/docs/site-builder/` (or query the
> MCP server if connected).

Dynamic page system: a record in the `builder-pages` collection is served at request
time by the page router. **No build/generate step** — add a page, it's live.

## Starters

`vendor/bin/tcms builder:init <starter>` (`--list` to enumerate, `--force` to overwrite):
- `minimal` — bare layout + one page
- `blog` — blog index + post pages (uses the `blog` collection)
- `business` — multi-page marketing site
- `portfolio` — project gallery site

Add `--frontend` to also scaffold the Vite pipeline (see `frontend.md`).

## Template directories

Live on the filesystem under `tcms-data/builder/`:
- `layouts/` — page shells (`<html>`, head, nav, footer)
- `pages/` — per-page templates (referenced by a page's `template` field)
- `partials/` — reusable fragments (`{% include %}`)
- `macros/` — reusable `{% macro %}` definitions

## builder-pages fields

| Field | Purpose |
|---|---|
| `id` | object id |
| `title` | page title |
| `route` | URL pattern; may contain `{id}`-style placeholders |
| `template` | path under `pages/` to render (e.g. `pages/page.twig`) |
| `description` | SEO meta description for the page |
| `image` | page image used for `og:image` social previews and optional hero rendering |
| `seo` | SEO card: title (with `${property}` placeholders), description, social image, canonical, noindex/nofollow, structured-data type. Empty = derive everything |
| `draft` | hide from routing when true |
| `nav` | include in `cms.builder.nav()` output (defaults to on) |
| `data` | free-form JSON, exposed in the template as `page.data.*` |
| `status` | HTTP status to return |
| `redirectTo` | target path for redirects |
| `sitemap` | include in generated sitemap |
| `middleware` | middleware to apply |
| `accessGroups` | access groups gating the page |

## Routing

- A `route` containing `{...}` placeholders (e.g. `/blog/{id}`) is **templated** and
  implicitly pretty; it dispatches through the object URL builder.
- The `prettyUrl` flag only applies to non-templated URL prefixes.
- `vendor/bin/tcms builder:routes` prints the full routing table and flags conflicts.

## SEO in layouts and detail pages

Every starter layout has `{% block seo %}{{ cms.seo.head(page|default(null)) }}{% endblock %}`
in `<head>`. That one call emits `<title>`, meta description, canonical, robots,
Open Graph/Twitter tags, verification tags and a JSON-LD graph, resolved from the
record's SEO card → the collection's field mapping → the Site SEO record. Rules:

- A hand-written layout adopting `cms.seo.head()` must **delete its own `<title>`
  and `<meta name="description">`** or the page ships two of each.
- A detail page (`/blog/{id}`) overrides the block to describe the object, not
  the page record: `{% block seo %}{{ cms.seo.head(post, {collection: 'blog'}) }}{% endblock %}`.
  Always pass `collection` — an object array does not know where it came from.
- The collection needs its **URL** set or its objects get no canonical, no
  `og:url` and no Article node. The reserved `blog` collection ships with none.
- Site-wide values (site name, base URL, default image, verification tokens) live
  on the `seo-site` singleton collection, editable in the admin or via `object:patch`.
- Granular pieces: `cms.seo.title()`, `meta()`, `og()`, `canonical()`, `jsonld()`.

Full reference: `vendor/totalcms/cms/resources/docs/site-builder/seo.md`.

## Twig helpers (global `cms`)

- `cms.seo.head(subject, {collection: id})` — the whole `<head>` metadata block (above)
- `cms.builder.nav()` — nav items from pages with `nav: true`
- `cms.builder.url(pageId, params)` — build a URL to another page
- `cms.builder.css('css/style.css')`, `cms.builder.js(...)`, `cms.builder.asset(...)`
  — emit asset URLs with mtime cache-busting
- General data access: `cms.collection.objects(...)`, `cms.image(...)`,
  `cms.config('key')`, `cms.env`. Exact signatures: `vendor/totalcms/cms/resources/docs/twig/`.

## Snapshots

`vendor/bin/tcms builder:history` lists and restores prior versions of a builder template.
