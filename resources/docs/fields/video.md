---
title: "Video"
description: "Configure the video field in Total CMS: paste a URL from YouTube, Vimeo, Livid, Bunny, Cloudflare Stream, Loom, Wistia or Publitio (or a direct MP4/WebM link), and render it with cms.render.video()."
related:
  - fields/image-gallery
  - fields/file-depot
  - twig/render
  - twig/media
---

# Video

The `video` field is for videos hosted on an external service. The author pastes
the video's URL and, optionally, uploads a poster image; Total CMS detects the
provider, records the provider's own thumbnail on save, and renders the right
player from one Twig call.

T3 never stores or serves video bytes through this field — no upload limits, no
transcoding, no bandwidth liability. For a video file you host yourself, use the
**file field** plus `cms.media.stream()` instead — see [Use the file field for
local video](#use-the-file-field-for-local-video) below.

## Stored shape

```json
"promo": {
	"url"         : "https://youtu.be/abc123",
	"provider"    : "youtube",
	"videoId"     : "abc123",
	"thumbnail"   : "https://img.youtube.com/vi/abc123/hqdefault.jpg",
	"title"       : "Getting started with Total CMS",
	"aspectRatio" : "16:9",
	"poster"      : { "…standard image object…": true }
}
```

- **url** — exactly what the author pasted, after trim. The source of truth.
- **provider** — one of the provider ids below, `file` for a direct media URL,
  or `unknown`. Derived on save; never hand-edited.
- **videoId** — provider-specific id (for Bunny: `{libraryId}/{videoId}`; for
  Cloudflare Stream: `{customerCode}/{videoId}`; empty for `file`/`unknown`).
- **thumbnail** — the vendor's thumbnail URL, or `""` when none is available.
- **title** — the video's title where the provider returns one via oEmbed, else
  `""`. Used as the iframe's `title` attribute and in the admin table.
- **aspectRatio** — `"W:H"`, derived from the provider where available, else
  `"16:9"`. Rendered as the `--cms-video-ratio` custom property on the wrapper,
  which the stylesheet reads with `aspect-ratio: var(--cms-video-ratio, 16 / 9)`.
  Override the shape from your own CSS with a plain rule, no `!important`:

  ```css
  .cms-video-embed.square { aspect-ratio: 1 / 1; }
  ```
- **poster** — a standard image object, present only when the author uploaded
  one.

Everything except `url` and `poster` is a derived cache — re-saving the object
recomputes it.

The key set above is the whole shape. It is fixed by the field itself, not by a
sub-schema you can edit, so there is nothing else to configure and no extra
bookkeeping key stored alongside your data. `poster` is written only when a
poster has actually been uploaded.

## Providers

| Provider | Accepted URL forms | Thumbnail |
|---|---|---|
| `youtube` | Watch links, `youtu.be/{id}`, Shorts, `embed/{id}`, and playlist links (`youtube.com/playlist?list=…`) | Derived — `img.youtube.com/vi/{id}/hqdefault.jpg`. A playlist link embeds as a playlist player (`videoseries`) and has no thumbnail |
| `vimeo` | `vimeo.com/{id}`, an unlisted `vimeo.com/{id}/{hash}`, `player.vimeo.com/video/{id}` | Fetched via Vimeo's oEmbed endpoint |
| `livid` | `livid.com/watch/{id}`, `livid.com/video/{id}`, `livid.com/embed/{id}` | Fetched via Livid's oEmbed endpoint |
| `bunny` | `iframe.mediadelivery.net/play/{lib}/{id}`, `iframe.mediadelivery.net/embed/{lib}/{id}` | Fetched through Bunny Stream's oEmbed endpoint |
| `cloudflare` | `customer-{code}.cloudflarestream.com/{id}/watch` or `/iframe`, `watch.cloudflarestream.com/{id}` | Derived from the customer code — the short `watch.cloudflarestream.com` form carries no customer code, so it has no thumbnail |
| `loom` | `loom.com/share/{id}`, `loom.com/embed/{id}` | Fetched via Loom's oEmbed endpoint |
| `wistia` | `{account}.wistia.com/medias/{id}`, `fast.wistia.net/embed/iframe/{id}` | Fetched via Wistia's oEmbed endpoint |
| `publitio` | The player page — `media.publit.io/file/{path}.html` or the same path on your own Publitio domain, with or without `?player={id}` | Derived — the page's own full-width poster (`/file/w_1280/{path}.jpg`). Title and dimensions come from Publitio's oEmbed endpoint |
| `file` | A URL ending in `.mp4`, `.webm`, `.mov`, `.m4v` or `.ogv` (query string ignored) | None — rendered as a `<video>` element instead of an iframe |
| `unknown` | Any other `http(s)` URL | None — rendered as a generic iframe, the author's URL unmodified |

Adding a provider is a code change, not a config option — if you need one that
isn't listed, ask.

Publitio notes: the player page ignores autoplay, loop and muted, so in facade
mode the viewer clicks play once more after the poster. Paste the `.mp4` link
instead of the `.html` one when you want a native `<video>` element — for an
animated GIF you uploaded to Publitio that is the link to use, since its
player page only shows a still image.

## What is derived, and when

On save:

1. `url` is trimmed. An empty URL clears every derived key and keeps `poster`.
2. The provider, video id and a default aspect ratio are always recomputed from
   the URL — cheap and pure, no network call.
3. The thumbnail and title are fetched **only when the stored `thumbnail` is
   empty and the URL's resolved provider/video id differ from what was stored
   last time** (or nothing was stored yet). The admin field blanks `thumbnail`
   and `title` whenever the URL input changes, so editing the URL always
   re-fetches, and an unchanged URL never re-fetches on every save. A failed
   fetch is retried when the URL changes, not on every subsequent save of the
   same URL. An API or MCP write that sends only `url` gets the same behavior
   for free.
4. YouTube and Cloudflare thumbnails are derived without a request; every other
   provider makes one oEmbed call. **A failed fetch (timeout, non-200,
   malformed response) never blocks or fails the save** — the derived fields
   are simply left empty and the object saves.
5. An uploaded `poster` goes through the same image processing (hashing,
   palette, EXIF, etc.) as any other uploaded image.

## Field settings

```json
"promo": {
	"field"    : "video",
	"label"    : "Promo Video",
	"settings" : {
		"providers" : ["youtube", "vimeo", "livid"],
		"poster"    : { "rules": { "size": { "max": 2000 } } }
	}
}
```

- **providers** — an allow-list of provider ids. A URL that resolves to a
  provider not in the list is rejected with a 400 validation error. Omit it to
  allow every provider.
- **poster** — a settings block forwarded to the poster's underlying image
  field, so the same rules (size, dimensions, file type, etc.) documented in
  [Image & Gallery](docs/fields/image-gallery) apply.

## The ready-made Video collection

**Setup Default Collections** creates a `video` collection alongside `image` and `file`: one `video` property per object, for a library of hosted videos you reference from other content. Both `collection` and `property` default to `video`, exactly as `cms.render.image()` defaults to the `image` collection, so an object from this collection renders by id alone:

```twig
{# The object with id "intro" in the video collection #}
{{ cms.render.video('intro') }}

{# The same object with its poster resized through ImageWorks #}
{{ cms.render.video('intro', {}, {w: 1200}) }}

{# An eager iframe instead of the click-to-play poster #}
{{ cms.render.video('intro', {facade: false}) }}

{# Every video in the collection #}
{% for video in cms.collection.objects('video') %}
	{{ cms.render.video(video) }}
{% endfor %}
```

The poster helper takes the same defaults: `{{ cms.media.videoPoster('intro', {w: 800}) }}`.

## Admin field and collection table

The admin field is a URL input with a media box below it. The box shows the
uploaded poster, else the vendor thumbnail, else an empty image dropzone, and
it is the poster's drop target: drag an image onto it (or click the upload arrow) to
set a poster over the vendor thumbnail. A chip in the corner says which one is
showing. Hover the poster for its edit (alt text, focal point), download,
replace and delete actions; deleting the poster drops the box back to the
vendor thumbnail. The URL row carries a provider badge and the stored title.
The collection table shows the same thumbnail and title for each object.

## Inside a card or deck

A `video` property works inside a card or a deck item exactly as at the top
level: the URL is resolved on save, the poster uploads through the same media
box, and the stored shape is the same object one level down. Render it with
the dotted property path, and the poster helper follows the same path:

```twig
{{ cms.render.video(post, {property: 'hero.promo'}) }}
{{ cms.media.videoPoster(post, {w: 800}, {property: 'hero.promo'}) }}

{% for item in post.slides %}
	{{ cms.render.video(post, {property: 'slides.' ~ item.id ~ '.promo'}) }}
{% endfor %}
```

The nested poster lives on disk at `{card}/{video}/poster` (or
`{deck}/{item}/{video}/poster`) and `tcms repair:files` knows to look there. A
card inside a card is not supported, as before. The MCP write guard that
refuses a `poster` in a payload applies to top-level video properties only.

## CSV

A video exports as one column holding just its URL. On import the same column
is accepted as a bare URL, and the save pipeline derives the provider,
thumbnail, title and aspect ratio again. The poster is a file and does not
travel through CSV. The same applies to a video inside a card, whose column is
`{card}.{video}`.

## Rendering with `cms.render.video()`

```twig
cms.render.video(object, {options}, {posterImageworks})
```

The video options come first because this is a video API; the poster
transform is the secondary knob, so it trails. That is the mirror of
`cms.render.image(object, {imageworks}, {options})`, where the transform is
the point and leads.

| Option | Type | Default | Description |
|---|---|---|---|
| `collection` | string | `'video'` | Collection identifier |
| `property` | string | `'video'` | Property name |
| `autoplay` | bool | `false` | Autoplay (subject to browser muted-autoplay rules) |
| `loop` | bool | `false` | Loop playback |
| `muted` | bool | `false` | Mute |
| `controls` | bool | `true` | Show player controls (`file` provider only) |
| `class` | string | `''` | Extra CSS class on the wrapper |
| `poster` | string | `''` | Override poster URL — otherwise resolved via `cms.media.videoPoster()` |
| `facade` | bool | `true` | Click-to-play poster with a play button; the iframe loads on click. Set `false` for an eager iframe. Ignored for the `file` provider, and for any value where no poster or thumbnail resolves, which renders the eager iframe instead (an `unknown` URL has no vendor thumbnail, so the poster must be uploaded) |

The optional third argument is an ImageWorks parameter array (`{w: 800, fm: 'webp'}`)
applied to an uploaded poster, the same array `cms.render.image()` takes. It is
ignored for a vendor thumbnail or a `poster` override, which are not served by T3.

Hosted provider (YouTube, Vimeo, Livid, Bunny, Cloudflare, Loom, Wistia, Publitio) — by
default a click-to-play facade: the poster (uploaded, else the vendor
thumbnail) with a play button, and the iframe only loads on click. Pass
`property` when the video sits on your own schema; leave both options out for
an object from the ready-made `video` collection:

```twig
{{ cms.render.video(post, {property: 'promo'}) }}
{{ cms.render.video('intro') }}
```

The uploaded poster resized through ImageWorks, and an eager iframe instead
of the facade:

```twig
{{ cms.render.video(post, {property: 'promo'}, {w: 1200, fm: 'webp'}) }}
{{ cms.render.video(post, {property: 'promo', facade: false}) }}
```

Direct file URL (the `file` provider) — a `<video>` element instead of an
iframe, here as a muted looping background clip:

```twig
{{ cms.render.video(post, {property: 'trailer', autoplay: true, muted: true, loop: true}) }}
```

The facade is on by default: it renders the poster with a play button and
swaps in the iframe on click, saving the iframe weight on pages listing many
videos. The first hover (or touch) preconnects to the player's hosts so the
click has less to wait for, and the poster stays on screen until the iframe
has loaded, so there is no empty box while the player boots. It is skipped for the `file` provider, and for any value where no
poster or thumbnail resolves (the eager embed renders instead) — for an
`unknown` URL specifically, the poster must be uploaded, since there is no
vendor thumbnail. `muted` and `loop` carry into the embed the click builds:

```twig
{{ cms.render.video(post, {property: 'promo', muted: true}) }}
```

A file-field value (any `file` property whose stored `mime` starts with
`video/`) renders too, through the same streaming route a plain video upload
already uses — so a template can treat local and hosted video through one
call:

```twig
{{ cms.render.video(post, {property: 'localClip'}) }}
```

An `unknown` URL is never modified — it renders as a generic iframe with the
author's URL exactly as pasted, the same trust model `embed()` already uses.

## `cms.media.videoPoster()`

```twig
{{ cms.media.videoPoster(post, {w: 800}, {property: 'promo'}) }}
```

Returns the poster URL used by `cms.render.video()`: the uploaded poster (run
through ImageWorks, so it gets resizing and format negotiation) when present,
else the vendor `thumbnail` string, else `''`.

## MCP

Writing `url` through `create`, `update` or `patch` is allowed — the save
pipeline resolves the rest. Writing `poster` is refused, the same way any
image upload is refused over MCP. A payload for a video property that omits
`poster` entirely keeps the object's current poster untouched — `update` and
`patch` never drop an uploaded poster just because the caller didn't send it.

## Use the file field for local video

The video field never accepts an upload and never stores video bytes. For
video you host yourself — background clips, small local files — use a **file**
field and [`cms.media.stream()`](docs/twig/media#stream) / `cms.render.video()`
against that file-field value, which supports HTTP range requests for
in-browser playback.
