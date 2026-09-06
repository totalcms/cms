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

## Podcasts

Add a `podcast` block to the feed details and to each item and the feed gains
the iTunes and Podcast Index tags Apple Podcasts, Spotify and the open
directories read. Leave it out and the feed is exactly what it was.

If your show uses the built-in `podcast` and `podcast-episode` schemas, you do
not need any of this: `cms.feed.podcast('podcast', 'episodes')` fills the block
in from those collections, serves uploaded audio through the counted
streaming route or links to audio hosted elsewhere, and takes the feed URL
from the show record. See [Podcasts](docs/collections/podcast). What follows
is for feeds built from your own schema.

```twig
{{ cms.feed.rss({
    title: show.title, link: '/', self: '/podcast.xml', description: show.description,
    podcast: {
        author:   show.author,
        owner:    {name: show.author, email: show.email},
        image:    'https://example.com/media/cover.jpg',
        category: ['Technology', 'Business > Entrepreneurship'],
        explicit: false,
    }
}, episodes|sortBy('-date')|map(e => {
    id: e.id, title: e.title, link: cms.builder.url('episode', {id: e.id}),
    date: e.date, content: e.notes|markdown,
    media: {url: cms.media.stream(e, {property: 'audio'}), type: e.audio.mime, length: e.audio.size},
    podcast: {duration: e.duration, episode: e.number, season: e.season},
})) }}
```

### What Apple requires

When `podcast` is present these are required, and the feed refuses to render
without them: `author`, `owner` (`{name, email}`), `image`, `category`,
`explicit`, and the feed's `self` URL.

- **`image`** must be an absolute URL ending in `.jpg` or `.png`, with no query
  string, 1400–3000 px square. An ImageWorks URL with parameters is rejected,
  and so is `.jpeg`; point at the stored file, or serve a resized copy at a
  plain path.
- **`category`** is a string or a list, from Apple's category list.
  Sub-categories are written `Parent > Child`. A category that is not on the
  list fails with the closest matches named.
- **`explicit`** is `true` or `false`.
- **`self`** is what apps re-fetch with, and the Podcast Index `guid` is
  derived from it. Keep it stable for the life of the show.

### Feed keys

| Key | Tag | Notes |
| --- | --- | --- |
| `author`, `owner`, `image`, `category`, `explicit` | required | see above |
| `type` | `itunes:type` | `episodic` (default) or `serial` |
| `subtitle`, `summary` | | summary defaults to `description` |
| `newFeedUrl` | `itunes:new-feed-url` | when the feed moves |
| `complete`, `block` | | booleans |
| `guid` | `podcast:guid` | derived from `self` when omitted |
| `locked` | `podcast:locked` | `{owner: email, value: true}` |
| `funding` | `podcast:funding` | `{url, title}` or a list of them |

### Item keys

| Key | Tag | Notes |
| --- | --- | --- |
| `duration` | `itunes:duration` | seconds, or `HH:MM:SS` (written as seconds) |
| `episode`, `season` | | whole numbers |
| `episodeType` | | `full` (default), `trailer`, `bonus` |
| `image` | | per-episode art, same rules as the feed image |
| `explicit` | | overrides the feed value |
| `title`, `subtitle`, `summary` | | summary defaults to the item summary, then the content with tags stripped |
| `block` | | boolean |
| `transcript` | `podcast:transcript` | `{url, type}` — e.g. `application/srt` |
| `chapters` | `podcast:chapters` | `{url, type: 'application/json+chapters'}` |
| `people` | `podcast:person` | list of `{name, role?, group?, img?, href?}` |
| `soundbites` | `podcast:soundbite` | list of `{startTime, duration, title?}` |

The enclosure is `media`, exactly as above. Podcast apps cache the enclosure
URL for a long time, so it must be a public, stable URL; a signed or expiring
link is a broken episode later.

### Downloads

Total CMS does not count downloads. Podcasters use a prefix analytics service
instead, which is one change to the enclosure URL:

```twig
media: {url: 'https://op3.dev/e/' ~ (audioUrl|replace({'https://': ''})), type: e.audio.mime, length: e.audio.size},
```

Atom feeds ignore the `podcast` block: podcast apps read RSS.

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
