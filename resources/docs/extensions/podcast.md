---
title: "Podcast (Bundled Extension)"
description: "Host a podcast from two collections: the podcast and podcast-episode schemas, a feed the directories accept at its own address, and a podcast_feed() Twig function."
since: "3.5.3"
---

# Podcast

`totalcms/podcast` — bundled with Total CMS, Standard edition and above. Ships the `podcast` (show) and `podcast-episode` schemas, serves a feed the directories accept without any page or template, and adds `podcast_feed()` for sites that want the feed on a page of their own. The RSS itself — the iTunes and Podcast Index tags — is part of core (`cms.feed.rss()` with a `podcast` block); the extension is the two schemas and the mapping from records to that block.

## Enabling

1. Go to **Admin → Extensions**, find **Podcast**, click **Enable** (or run `tcms extension:enable totalcms/podcast`).
2. Create an episodes collection from `podcast-episode`, then a Single Object Collection from `podcast` named `podcast`, and pick the episodes collection on the show record.
3. Fill in the show and add episodes. The [Podcasts](docs/collections/podcast) page walks through every field.

## The feed

| Address | Serves |
|---|---|
| `/api/ext/totalcms/podcast/feed` | the show in the collection named `podcast` |
| `/api/ext/totalcms/podcast/feed/{show}` | the show in collection `{show}` — a site can host several |

Both answer `application/rss+xml` with five minutes of caching, and 404 with a message when the show has no record or names no episodes collection. Put the address in the show's **Feed URL** and submit it to Apple Podcasts, Spotify and the open directories.

## The Twig function

```twig
{{ podcast_feed() }}
{{ podcast_feed('second-show', {language: 'en-US', copyright: '© Joe'}) }}
```

The first argument is the show collection, default `podcast`; the episodes collection comes from the show record. Options are `link`, `language`, `copyright`, `self` and `now`. Render it as the whole content of a page whose route ends in `.xml` and the router serves it with the right type.

## What it replaces

Before 3.5.3 the two schemas were reserved schemas in core and the call was `cms.feed.podcast(show, episodes)`. Neither shipped in a release. The extension form keeps podcasting out of every install that does not need it, and doubles as the reference for shipping a whole content type as an extension.
