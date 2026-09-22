---
name: totalcms-podcast
description: Use when this Total CMS site hosts a podcast — creating or editing the show and episode collections made from the podcast and podcast-episode schemas, publishing or moving the RSS feed, rendering podcast_feed() on a page, hosting several shows, or answering why a feed 404s or a directory rejects it.
---

# Podcast (bundled extension)

The `totalcms/podcast` extension is enabled on this site (Standard edition and
above). It adds two schemas, one feed route and one Twig function. The RSS
writer itself — iTunes and Podcast Index tags — is core; the extension is the
schemas and the mapping from records to that feed. Full reference:
`vendor/totalcms/cms/resources/docs/collections/podcast.md`.

## The shape

- **One show = one Single Object Collection** made from the `podcast` schema.
  The default feed serves the collection named `podcast`.
- **Episodes = one ordinary collection** made from `podcast-episode`. The show
  record's **Episodes Collection** field names it. A site hosts several shows
  by repeating the pair; each show has its own feed.
- Neither collection is created by Setup Default Collections. Create them:

```bash
vendor/bin/tcms collection:create episodes --schema=podcast-episode
vendor/bin/tcms collection:create podcast --schema=podcast --singleton
```

Then set `episodes` on the show record. Give the episodes collection a URL in
its settings only if episodes get their own pages; the feed links each item
to it.

## The feed

| Address | Serves |
|---|---|
| `/api/ext/totalcms/podcast/feed` | the show in the collection named `podcast` |
| `/api/ext/totalcms/podcast/feed/{show}` | the show in collection `{show}` |

`application/rss+xml`, cached five minutes, no page or template involved, and
the same on a Stacks site as on Site Builder. Hand that address to Apple
Podcasts Connect, Spotify for Podcasters and the Podcast Index. A 404 carries
a message saying which is missing: the show record, or its episodes
collection.

To serve the feed at an address of your own, a Site Builder page whose route
ends in `.xml` (the router then sends the XML type) with the template:

```twig
{{ podcast_feed() }}
{{ podcast_feed('second-show', {language: 'en-US', copyright: '© Acme', link: '/'}) }}
```

First argument: the show collection, default `podcast`. Options: `link`,
`language`, `copyright`, `now`, `self`. The page's own URL becomes the feed's
address.

## Fields that matter

Show (`podcast`): everything Apple requires is a required field, so a record
that saves is a feed the directories accept. `cover` must be square, 1400 to
3000 px, JPG or PNG. `explicit` must be answered either way. `ownerEmail` is
never shown in apps. `type` is `episodic` (newest first) or `serial` (in
order). `guid` stays blank unless migrating an existing show.

Episode (`podcast-episode`): `id` becomes the item GUID, so never change it
after publishing. `date` in the future holds the episode back; `draft` leaves
it out. Audio is either an uploaded `audio` file (served and counted by Total
CMS) or an `audioUrl` on your own host plus `audioLength` in bytes. `content`
is the show notes; `summary` is the optional plain-text short form. `art`
overrides the show cover for that episode.

## Moving hosts or feed URLs

The show's Podcast Index GUID is derived from the feed address. Before serving
the feed from a new address, copy the current GUID from the live feed into the
show's `guid` field and set `newFeedUrl` so apps follow. Changing the GUID
resets every listener's subscription.

## Scale and analytics

Uploaded audio streams through Total CMS and every fetch increments the file's
download count, readable in Twig as `episode.audio.count`. It counts
validators and re-downloads too, so treat it as a trend. A show with real reach
should host audio in a bucket via `audioUrl`; those fetches are not counted,
so put a prefix analytics service in front of the URL instead.

## Common mistakes

- A feed that 404s almost always means the show collection is not named
  `podcast`, has no record yet, or its Episodes Collection is unset.
- Rendering `podcast_feed()` on a route that does not end in `.xml` serves it
  as HTML and the directories reject it.
- Editing an episode `id` after submission republishes it as a new episode.
- The schemas are read-only and owned by the extension; customize a copy under
  `tcms-data/.schemas/` rather than editing the extension's files.
