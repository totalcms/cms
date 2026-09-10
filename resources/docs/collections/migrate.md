---
title: "Migrating from another CMS"
description: "Move content into Total CMS from a WordPress WXR export, Alloy, Total CMS 1, or an RSS/Atom/JSON feed using the built-in platform importers."
related:
  - collections/import
  - operations/migration-total-cms-one
  - extensions/events
  - operations/search
updated: 2026-09-10
---

# Migrating from another CMS

Total CMS ships importers for four other sources, under **Utilities** in the sidebar's **Import** group: **Import Alloy**, **Import RSS Feed**, **Import Total CMS 1** and **Import WordPress**. Each maps its source onto object properties and queues the result into a collection you choose, leaving the source data untouched. For a spreadsheet or JSON export, see [Importing Data](docs/collections/import) instead.

They only ever **create** objects. The RSS importer skips an entry whose id already exists, so re-polling a feed is safe; the others queue it anyway and the job fails, because Total CMS rejects a duplicate id rather than overwriting. The "update existing objects" rule in [Importing Data](docs/collections/import) covers the CSV and JSON forms, not these.

## WordPress

**Utilities → Import WordPress.** Requires the Standard edition or higher (the route is gated on the `rss_import` feature).

Generate the file in WordPress under **Tools → Export** and upload the `.xml` (WXR). Analyze it first: that step reports the post count, date range, every category, tag and author found, and the first ten posts. The import step takes the same file plus a target collection.

Only items whose `wp:post_type` is `post` are imported. Fields map like this:

| WXR field | Object property |
|---|---|
| `wp:post_name`, else a title slug | `id` |
| `title` | `title` |
| `content:encoded` | `content` |
| `excerpt:encoded` | `summary` |
| `wp:post_date` | `date`, as ISO 8601 |
| `dc:creator` | `author` |
| `category` with `domain="category"` | `category`, comma separated, `Uncategorized` dropped |
| `category` with `domain="post_tag"` | `tags`, comma separated |
| `link` | `url` |
| `wp:status` | `draft` — anything but `publish` is a draft |

The featured image is followed: `_thumbnail_id` is resolved against the export's attachment items, downloaded, and imported into `image`. Everything else is **not imported** — pages, custom post types, the rest of the media library, images embedded in post bodies (they keep pointing at the old site), comments, users, menus, and anything a plugin or shortcode produced. The author is a name string, not a user object. "Import as drafts" overrides the status row above. No CLI command.

## Alloy

**Utilities → Import Alloy.** No edition requirement.

You supply four folder names relative to the document root, defaulting to `posts`, `image-uploads`, `embeds` and `droplets`. Analyze first for a count of what was found.

- **Blog posts** — each `.md` file is YAML frontmatter plus a Markdown body, landing in the `blog` collection (created with the `blog` schema if missing). A filename of `YYYY-MM-DD_slug.md` supplies `date` and `id`; otherwise the whole filename is the id. `title`, `author`, `category`, `tags`, `draft` and `summary` come from the frontmatter, and the body becomes HTML in `content`. `topper` resolves against the image uploads folder into `image`, with `topperalt` as `imageAlt`.
- **Embeds** — converted from Markdown to HTML and stored as `styledtext` in the `styledtext` collection.
- **Droplets** — `type: text` becomes an object in the `text` collection; `type: image` becomes one in the `image` collection, resolved against the image uploads folder. Any other type is skipped and logged.

No CLI command.

## Total CMS 1

**Utilities → Import Total CMS 1.** No edition requirement. The page auto-detects a `cms-data` folder in your document root, or you can type an absolute path.

Each v1 folder becomes a collection: blogs get one each on the `blog-legacy` schema (a `.posturl` file sets the collection URL and pretty-URL flag; if a non-empty collection with that id exists, the import goes to `<id>-one`), `date`, `depot`, `feed`, `file`, `gallery`, `image` and `text` map to collections of the same name, and videos become `url` objects. Templates are the other half of a v1 migration: the folder-by-folder table and the full macro → Twig mapping live in [Migrating from Total CMS v1](docs/operations/migration-total-cms-one).

## RSS, Atom and JSON feeds

**Utilities → Import RSS Feed.** Requires the Standard edition or higher.

Give it any RSS, Atom or JSON feed URL and analyze it to see the feed title, description, entry count and a per-entry preview. The object `id` is a slug of the entry title.

The default mapping is `title → title`, `content → content`, `summary → summary`, `date → date`, `author → author`, `categories → categories` and `link → media`. Change any of it in the field mapping panel; an empty target skips that field. An entry with no content falls back to its description (or JSON `summary`). An image is taken from the entry's enclosure, `media:content` or `media:thumbnail` — or `image` / `banner_image` in a JSON Feed — downloaded, and written to `image`.

The same import runs from the CLI, which is what you schedule with cron:

```
tcms rss:import https://example.com/feed.xml blog --no-draft \
  --map title=heading --map image=featured
```

`--map` accepts `title`, `content`, `summary`, `date`, `author`, `categories`, `link` and `image`. Use `--user-agent` when a host blocks the default (`TotalCMS/<version> (+https://totalcms.co)`). The admin page's "Schedule with cron" panel builds this command line for you.

## How the import runs

Every importer above writes into the job queue rather than saving immediately, so "queued N items" counts jobs, not saved objects. Drain the queue with the scheduled job processor:

```
php <install_dir>/resources/bin/tcms jobs:process
```

Watch progress under **Utilities → Job Queue Manager**, which also builds that cron line. A bad item does not stop an import: the failure is logged and the importer moves on. Those messages go to `importer.log`, readable in **Utilities → Log Analyzer**, with warnings and errors mirrored into `totalcms.log`. Each imported object fires `import.created` rather than `object.created` — see [Events](docs/extensions/events) if listeners or automations watch for new content.

## After importing

Check a few objects — dates, images, and anything that was a category or tag list — and publish if you imported as drafts.

Ids come from the source slug wherever one exists (`wp:post_name`, the Alloy filename, the v1 permalink), so old URLs are recoverable: set the collection's URL to match and redirect old paths at the web server. Feed entries have no source slug, so their ids are slugs of their titles.

Finally, rebuild your search index. With `indexOnSave` enabled, every imported object is pushed to the search provider as its job runs — slow, and hard on an external provider. Turn it off for a large migration, then run:

```
tcms search:reindex
```

See [Search Backends](docs/operations/search) for the provider settings.
