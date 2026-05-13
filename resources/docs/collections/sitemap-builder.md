---
title: "Sitemaps"
description: "Generate XML sitemaps for Total CMS collections and builder pages, with admin-managed defaults, a sitemap index at /sitemap.xml, and backward-compatible URL parameters."
---

# Sitemaps

Total CMS generates XML sitemaps for collections and builder pages, with a sitemap index that crawlers can discover via `robots.txt`.

## Sitemap Index

The index lists every sitemap available on the site and is served at two paths:

```
/sitemap.xml
/sitemap
```

The `.xml` form is what crawlers conventionally expect to find declared in `robots.txt`. Both URLs return the same XML.

The index automatically includes:

- **The pages sitemap** (`/sitemap/-pages`) when the builder pages collection exists
- **One entry per collection** that has been opted in via the Sitemap card on the collection edit form

Collections that haven't been opted in are silently omitted, and `/sitemap/{collection}` returns 404 for them. This keeps disabled or unconfigured collections completely off the public surface — useful for collections that hold sensitive data.

### Example `robots.txt`

```
Sitemap: https://example.com/sitemap.xml
```

## Configuring a Collection

Each collection has a **Sitemap** card on its edit form (`/admin/collections/{collection}/edit`). The card persists the following settings:

| Setting | What it does |
|---|---|
| **Include in Sitemap** | Master toggle. Off → `/sitemap/{collection}` returns 404 and the collection is omitted from the index. |
| **Date Property** | Object property used for `<lastmod>`. Pulled from the collection's object schema. Defaults to `updated`. |
| **Change Frequency** | Hint for crawlers (`always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never`). Most search engines treat this as advisory. |
| **Priority** | Default crawl priority for objects (0.0 to 1.0). |
| **Include Filter** | Only include objects matching this expression (e.g. `published:true`). |
| **Exclude Filter** | Skip objects matching this expression (e.g. `draft:true`). |

Saving the collection writes these into the collection's `.meta.json` under a `sitemap` block. The `/sitemap/{collection}` route reads them as defaults — no query string required for routine use.

## Pages Sitemap

When the builder pages collection exists, the pages sitemap is served at:

```
/sitemap/-pages
```

The leading `-` keeps it from colliding with a user collection literally named `pages`. The index references this path automatically.

Each builder page has its own sitemap controls in the page form: an **Include in Sitemap** toggle, a **Change Frequency** select, and a **Priority** number — analogous to the collection-level card but per page. Dynamic routes (containing `{` placeholders, e.g. `/blog/{id}`) are skipped automatically since they can't be enumerated from the route alone.

## Per-Object Sitemap

Generate a sitemap for the objects in any opted-in collection:

```
/sitemap/{collection}
```

For example:
```
/sitemap/blog
/sitemap/products
```

URLs of disabled collections return 404 — this is intentional so that the existence of a sitemap-disabled collection is not exposed to crawlers or attackers.

### URL Parameter Overrides

The collection's saved Sitemap card settings act as defaults. URL parameters still work for one-off tweaks and override the saved values:

```
# Override the date property for one request
/sitemap/blog?date=created

# Override the saved exclude filter
/sitemap/blog?exclude=draft,archived

# Override frequency and priority
/sitemap/blog?frequency=daily&priority=0.8
```

This preserves backward compatibility with workflows that built sitemap URLs by hand. The saved settings reduce the need for query strings, but they don't remove the ability to use them.

> **Security note:** URL parameters cannot bypass the **Include in Sitemap** toggle. A disabled collection always returns 404 regardless of query string.

## Filtering Objects

The `include` and `exclude` settings — whether saved on the collection or passed as URL parameters — control which objects appear in the sitemap.

> **📖 See [Index Filter Documentation](docs/api/index-filter) for complete filtering syntax and examples.**

### Quick Examples

```
# Exclude draft posts
/sitemap/blog?exclude=draft

# Only featured posts
/sitemap/blog?include=featured

# Only published, non-private posts
/sitemap/blog?include=published&exclude=draft,private

# Only in-stock products
/sitemap/products?include=instock&exclude=discontinued
```

### Filter Syntax Overview

- **Include**: `?include=property:value` — Object must match ALL criteria
- **Exclude**: `?exclude=property:value` — Object excluded if it matches ANY criteria
- **Shorthand**: `?include=featured` — Defaults to `featured:true`
- **Multiple**: Use commas to separate multiple filters

For complete filtering documentation including boolean/string handling, multi-criteria, precedence, and PHP usage examples, see the [Index Filter Documentation](docs/api/index-filter).

## Backward Compatibility

- Existing sitemap URLs continue to work — once a collection is opted in via the Sitemap card, `/sitemap/{collection}` behaves the same as before.
- The legacy `filter` URL parameter is still supported and automatically mapped to `include`.
- Saved Sitemap card settings act as defaults; URL parameters override them on a per-request basis.

## Security Model

- A collection without an enabled Sitemap card returns 404 — both at the per-collection URL and absent from the index.
- The 404 response is identical to a missing-resource 404, so the existence of a disabled-but-present sitemap configuration is not leaked.
- The **Include in Sitemap** toggle is the single source of truth — URL parameters cannot bypass it.
