---
title: "Podcasts"
description: "Publish a podcast from Total CMS: create the show and episode collections from the built-in schemas, upload episodes, and serve a feed Apple Podcasts and Spotify accept with one line of Twig."
related:
  - twig/feeds
  - fields/file-depot
audience: beginner
updated: 2026-09-06
---

# Podcasts

Total CMS ships two schemas and one Twig call that together give you a podcast
feed the directories accept: `podcast` for the show, `podcast-episode` for the
episodes, and `cms.feed.podcast()` to turn them into RSS. Standard edition and
above. There is no admin screen for the feed itself; the collections are the
editor, and the feed is a page.

## 1. Create the two collections

**The show.** Create a collection from the `podcast` schema and tick **Single
Object Collection** in its settings. A show has exactly one record, so
opening the collection opens the record. Call the collection `podcast`.

**The episodes.** Create a second collection from the `podcast-episode` schema.
Call it `episodes`, or anything you like — you name it in the feed call. Give
it a URL (for example `/episodes/`) if episodes have their own pages; the feed
links each episode to it.

Neither collection is created for you by **Setup Default Collections**. That
is deliberate: most sites do not have a podcast.

## 2. Fill in the show

Everything Apple requires is a required field, so the form will not let you
save a show the directories would reject:

- **Cover art** must be square, between 1400 and 3000 pixels on a side, and a
  JPG or PNG. Upload the real file at that size; the feed serves it as-is.
- **Categories** come from Apple's list. Choose up to three. The first one is
  the primary category that directories show.
- **Feed URL** is the public address of the feed you are about to publish,
  for example `https://example.com/podcast.xml`. Apps re-fetch with it, and
  the show's permanent identifier is derived from it. Set it once.
- **Owner email** is where Apple and Spotify send ownership notices. It goes
  in the feed but is never shown in apps.
- **Explicit** must be answered either way.

The optional fields are for later: **Funding** adds a support link in apps
that read the Podcast Index namespace, **Locked** tells other hosts not to
import your feed, and **New feed URL** is only for moving.

## 3. Add episodes

Each episode needs a title, a date, and its audio, which you can supply two
ways:

- **Upload it** to the Audio File field. Total CMS hosts it, serves it
  through the streaming route apps expect, and **counts every listen**: the
  number is on the file in the admin and available in Twig as
  `episode.audio.count`. MP3 is the safe choice; every app plays it.
- **Link it** with the Audio URL field when the file lives on your own host
  or storage bucket. Fill in the file size in bytes as well, since apps use
  it for download progress. Linked audio is not counted by Total CMS; see
  Download numbers below.

If both are set, the uploaded file wins. An episode with neither is left out
of the feed.

Fill in the **duration** in seconds if you know it, since apps use it for
progress bars. Episode and season numbers, the episode type, show notes, a
summary, and per-episode art are all optional. Transcripts and chapter files
work the same way as audio: upload them, or link to them.

Two things keep an episode out of the feed: the **Draft** box, and a **date
in the future**. Schedule an episode by dating it ahead; it appears in the
feed when the date arrives.

## 4. Publish the feed

Make a page whose entire content is the feed. In Site Builder, add a page at
`/podcast.xml` whose template is:

```twig
{{ cms.feed.podcast('podcast', 'episodes') }}
```

The first argument is the show collection, the second the episodes
collection. The feed's own address comes from the show's **Feed URL** field,
so make sure the page you create matches it. Options: `link` (your site's
home page, default `/`), `language` (for example `en-US`), `copyright`, and
`self` if you need to override the feed URL for one rendering.

The page should be served as XML. In Site Builder set the page's content type
to `application/rss+xml`; in a Stacks project use a PHP page that sets the
header before calling the CMS.

That is the whole feed: the show's details, the episodes newest first, an
enclosure for each audio file, and the iTunes and Podcast Index tags the
directories read. If you would rather build the feed by hand from your own
schema, [Feeds](docs/twig/feeds) shows the `podcast` block that this call
fills in for you.

## 5. Validate and submit

Open the feed URL in a browser and check it renders as XML. Then run it
through a validator such as [podba.se/validate](https://podba.se/validate/)
or Apple's own checker in Podcasts Connect. A feed that renders in Total CMS
has already passed the checks the writer knows about, so the validators
mostly confirm the artwork dimensions and that the audio URLs are reachable.

Submit the feed URL to Apple Podcasts Connect, Spotify for Podcasters, and
the Podcast Index. Most other apps read from those three.

## Moving hosts or feed URLs

Podcast apps identify your show by a permanent GUID, which Total CMS derives
from the show's **Feed URL** field. If you ever change that field, copy the
existing GUID from your feed into the show's **Podcast GUID** field first,
so the identifier survives, and set **New feed URL** so apps follow you.
Change the GUID and every listener's app sees a brand-new show.

Episode IDs work the same way: the ID is the item's identity in the feed.
Change it and the episode is re-announced as new.

## Large shows

Uploaded audio is served by Total CMS through the file field's streaming
route, which is fine for a few hundred downloads an episode. A show with real
reach is better served from your own storage bucket: bandwidth stops being
your web host's problem and byte-range requests, which apps rely on for
scrubbing, are handled by the bucket. Today that means uploading to the
bucket yourself and using the Audio URL field. The S3 Uploads extension will
make that a drag-and-drop upload that is still counted.

## Download numbers

Uploaded episodes are counted: every fetch through the streaming route adds
one to the file's download count, which you can see on the file in the admin
and read in Twig as `episode.audio.count`. It is a plain fetch count, so it
includes validators and re-downloads; treat it as a trend, not an audit.

Linked episodes are not counted, because the audio never passes through
Total CMS. Independent podcasters use a prefix analytics service in front of
the audio URL instead. If you build your feed by hand from
[Feeds](docs/twig/feeds), the prefix is one string change on the enclosure
URL.
