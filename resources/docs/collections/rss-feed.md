---
title: "RSS Feeds"
description: "Publish a collection as an RSS feed at /feed/rss/{collection}: turn it on per collection, map the fields, choose what is left out, and build filtered variants with URL parameters."
related:
  - twig/feeds
  - collections/sitemap-builder
  - apis/index-filter
---

# RSS Feeds

A collection can publish its objects as an RSS feed at:

```
/feed/rss/{collection}
```

The feed is **off by default**. A collection publishes one only when **Publish RSS Feed** is turned on in its settings. For every other collection the URL returns 404, the same as for a collection that does not exist.

Collections created from the `blog` and `feed` schemas are the exception: they are created with the feed already on.

## Configuring a Collection

Each collection has an **RSS Feed** card on its edit form (`/admin/collections/{collection}/edit`). Turning on the toggle reveals the rest of the settings:

| Setting | What it does |
|---|---|
| **Publish RSS Feed** | Master toggle. Off → `/feed/rss/{collection}` returns 404. |
| **Feed Name** | The feed's title in a reader. Defaults to the site name. |
| **Language** | The language the feed is written in, picked from the site's locale list. |
| **Feed Description** | A short description. Defaults to the feed name. |
| **Link to Website** | The page the feed belongs to. Defaults to the site's homepage. |
| **Feed Image** | URL of an image that represents the feed. |
| **Include Filter** | Only include objects matching this expression (e.g. `featured:true`). |
| **Exclude Filter** | Skip objects matching this expression (e.g. `category:internal`). |
| **Item Limit** | The most items the feed lists, newest first. Defaults to 25; `-1` for no limit. |
| **Hidden Field** | Objects with this property turned on are left out of the feed. Defaults to `draft`. |
| **Title / Date / Content / Author / Media Property** | Which object property supplies each part of an item. Defaults: `title`, `updated`, `summary`, `author`, `media`. |

Saving the collection writes these into its `.meta.json` under a `feed` block.

If the Content Property is a `markdown` or `styledmarkdown` field, its Markdown is rendered to HTML for the feed, the same way the `|markdown` filter renders it. Other field types go out as written.

The feed reads the collection's **index**, not the full objects. A property you map, filter on or name as the Hidden Field must be in the schema's index.

## What Is Left Out

- **Hidden objects.** An object is left out when its Hidden Field is on. That field is `draft` unless you choose another, so drafts are left out of every feed by default, for every schema.
- **Objects without a URL.** An item needs a link, so the collection needs a URL and the object needs a complete one.

Choosing a different Hidden Field **replaces** `draft`. If a collection uses `archived` instead, objects with `archived` on are left out and `draft` no longer hides anything. To leave out both, keep the Hidden Field on one and add the other to the Exclude Filter, for example `draft:true`.

That is also how to publish a feed that includes drafts: point the Hidden Field at another property. Remember the feed is public.

The Hidden Field must be in the schema's index to take effect, and it can only be set on the collection. A URL cannot change it.

## URL Parameters

The saved settings are the feed. A URL may adjust only these, to build a variant of an enabled feed, such as one category or a shorter list:

| Parameter | Effect |
|---|---|
| `include` | Replaces the Include Filter |
| `exclude` | Replaces the Exclude Filter |
| `limit` | Replaces the Item Limit |
| `name` | Replaces the Feed Name |
| `description` | Replaces the Feed Description |

```
/feed/rss/blog?include=category:news&name=News%20Only&limit=10
```

Every other parameter is ignored. In particular, a URL cannot turn a feed on, cannot choose which properties appear, and cannot bring back hidden objects.

The **Feeds** utility on a collection (`/admin/collections/{collection}/-feeds`) builds these URLs for you.

## Locking a Feed Down Further

Anyone who can reach an enabled feed can use `include` and `exclude` to filter it by any indexed property. If that is more than you want, leave **Publish RSS Feed** off and serve the feed from a Site Builder page with [`cms.feed.rss()`](docs/twig/feeds) instead. The template then decides exactly which objects and fields go out, and no URL parameter can change it.

## Upgrading from 3.6.1 or Earlier

In 3.6.1 and earlier, every collection that had a URL served a feed, and the URL's parameters chose the fields. That made every indexed field of those collections public, and drafts were only left out for blogs. On upgrade:

- **Blog and feed collections keep their feed.** A migration turns **Publish RSS Feed** on for collections using the `blog`, `blog-legacy` or `feed` schema, or a schema that inherits from one.
- **Every other collection's feed stops.** Its URL returns 404 until you turn the feed on in that collection's settings.
- **Field mapping moves into the collection.** `title`, `content`, `date`, `author`, `media`, `link`, `image` and `language` in a feed URL are now ignored. If a feed URL used them, set the same values on the collection's RSS Feed card. A feed that mapped `content` to another property shows `summary` until you do.
- **Drafts are left out on every collection**, not only blogs, unless you change the collection's Hidden Field.
