---
title: "Feeds"
description: "Build RSS and Atom feeds in Twig with cms.feed.rss() and cms.feed.atom(), including enclosures for podcasts and media."
related:
  - twig/collections
  - twig/markdown
  - site-builder/overview
---

# Feeds

`cms.feed.rss()` and `cms.feed.atom()` build a syndication feed from two
arguments: the feed's own details, and a list of items. Everything about
*which* objects go in and *how* each one looks stays in the template, where
`|filter`, `|sortBy` and `|map` already live.

## A complete feed

Create a builder page with a route ending in `/rss` (or `.rss`), and a
template like this:

```twig
{{ cms.feed.rss(
  {
    title:       'Total CMS Releases',
    link:        cms.builder.canonicalUrl('changelog'),
    self:        cms.builder.canonicalUrl('changelog-rss'),
    description: 'New capabilities, refinements, and fixes.',
  },
  cms.collection.objects('changelog')|sortBy('date')|reverse|map(e => {
    title:   e.version ~ ' — ' ~ e.title,
    link:    cms.collection.canonicalObjectUrl('changelog', e),
    id:      e.id,
    date:    e.date,
    content: e.changelog|markdown,
  })
) }}
```

That is the whole template — no layout, no XML boilerplate. Escaping, CDATA,
RFC-2822 dates and the `atom:link` self reference are handled for you.

The route's extension sets the `Content-Type`, so `/changelog/rss` and
`/changelog/feed.rss` both serve `application/rss+xml`. See
[Site Builder](docs/site-builder/overview) for how that works.

## Feed details

| Key | Required | Notes |
| --- | --- | --- |
| `title` | yes | |
| `link` | yes | The page a reader should visit — not the feed's own URL |
| `description` | yes | |
| `self` | Atom only | The feed's own URL. Readers use it to re-fetch |
| `language` | no | e.g. `en-us` |
| `updated` | no | Defaults to the newest item's date |
| `generator` | no | |
| `copyright` | no | |

`self` is required for Atom because readers use it to re-subscribe, and there
is no safe value to guess — pointing it at `link` would hand every subscriber
a URL for the HTML page instead of the feed.

## Item details

| Key | Notes |
| --- | --- |
| `title` | |
| `link` | Made absolute against your domain if relative |
| `id` | The item's identity. Defaults to `link` |
| `date` | ISO string, timestamp, or `DateTime` |
| `content` | HTML. Run Markdown fields through `\|markdown` first |
| `summary` | Short form. RSS uses it in place of `content` if given |
| `author` | A name, or `{name, email, uri}` |
| `media` | An enclosure — see below |

Items are emitted in the order you supply them, so sort before you map.

### Why `id` matters

A feed reader treats the id as the item's identity and re-announces anything
whose id changes. Prefer something stable and independent of the URL — an
object id is ideal:

```twig
id: e.id,
```

Atom is stricter: an entry id must be an IRI, so a short key like `v3-5-0`
is not legal there. In an Atom feed the item link is used instead, which is
what feed generators generally do.

## Media and enclosures

`media` attaches an enclosure — a podcast episode, a video, an image. The
short form is a URL:

```twig
media: e.audio.link,
```

The type is guessed from the extension and the length reported as zero. For a
podcast, give real values instead — image and file fields already carry
`mime` and `size`:

```twig
media: {
  url:    e.audio.link,
  type:   e.audio.mime,
  length: e.audio.size,
},
```

Podcast clients use `length` for progress and buffering, so it is worth
supplying. One enclosure per item — RSS 2.0 permits no more.

## Atom

Same arguments, different renderer:

```twig
{{ cms.feed.atom(meta, items) }}
```

Serve it from a route ending in `.xml`, and remember `meta.self`.

## Compared with the built-in feed

Total CMS also serves `/feed/rss/{collection}` without any template. That
endpoint maps fields by name — which field is the title, which is the content
— and that is its limit: it cannot build a title out of two fields, and it
cannot run content through `|markdown`, so a Markdown field arrives at the
subscriber as raw `- **like this**`.

Use the endpoint when your collection already has a plain-text summary field
and a usable title. Build the feed in Twig when you need control over either.
