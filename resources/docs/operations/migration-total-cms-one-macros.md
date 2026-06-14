---
title: "Migrating Total CMS v1 Macros to Twig"
description: "Reference table mapping every Total CMS v1 page macro (%cmsText%, %cmsImage%, %cmsGalleryImageFeatured%, etc.) to its Total CMS 3 Twig equivalent in the cms.data, cms.media, and cms.render namespaces."
related:
  - twig/data
  - twig/media
  - twig/render
  - operations/migration-total-cms-one
---

# Migrating Total CMS v1 Macros to Twig

Total CMS v1 injected content into a page with `%macro(cmsid)%` tags placed directly in your HTML. Total CMS 3 has no macro processor — content is pulled into [Twig templates](docs/twig/overview) through the global `cms` variable instead. This page maps every v1 macro to its T3 Twig equivalent so you can update your templates field by field.

> This page covers the **template tags**. For migrating the underlying *content* (blogs, galleries, images, files, dates, etc.) out of `cms-data/`, see [Migration of Total CMS v1 Data](docs/operations/migration-total-cms-one).

## How the model changed

| Concept | Total CMS v1 | Total CMS 3 |
|---------|--------------|-------------|
| **Where tags live** | `%cmsText(headline)%` placed anywhere in the page HTML | `{{ cms.data.text('headline') }}` inside a `.twig` template |
| **Content addressing** | A single global `cmsid` per content type | A **collection** + object **id**. The reserved collections (`text`, `image`, `gallery`, `file`, `toggle`, `date`, …) are the defaults, so a lone `cmsid` still maps 1:1 |
| **Image sizes** | Fixed `Thumb` / `Square` presets baked into the macro name | Any dimensions on demand via [ImageWorks](docs/twig/imageworks) options — `{w: 300, h: 300, fit: 'crop'}` |
| **PNG output** | Separate `…Png…` macros | One option: `{fm: 'png'}` |
| **Markdown / formatting** | Separate `…Format` macros | A Twig filter: `| markdown` or `| markdownInline` |

Because the reserved-collection defaults match v1's content types, the `cmsid` you used in v1 becomes the object **id** in T3 with no extra arguments. If you migrated content into a *custom* collection, add a context object: `{collection: 'pages', property: 'body'}`.

## Text macros

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsData(cmsid)%` | Raw stored value | `{{ cms.data('text', 'cmsid', 'text') }}` |
| `%cmsText(cmsid)%` | Text | `{{ cms.data.text('cmsid') }}` |
| `%cmsTextFormat(cmsid)%` | Markdown → HTML | `{{ cms.data.text('cmsid')| markdown }}` |
| `%cmsTextStripHTML(cmsid)%` | Strip HTML | `{{ cms.data.text('cmsid')| striptags }}` |
| `%cmsToggle(cmsid)%` | Boolean toggle | `{{ cms.data.toggle('cmsid') }}` |

> `cms.data('text', 'cmsid', 'text')` is the [callable shorthand](docs/twig/data#raw-data-access) for `cms.data.raw(...)` — it returns the value exactly as stored. For everyday text use `cms.data.text('cmsid')`.

## Image alt text

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsImageAlt(cmsid)%` | Image alt | `{{ cms.render.alt('cmsid') }}` |
| `%cmsImageAltFormat(cmsid)%` | Image alt (markdown) | `{{ cms.render.alt('cmsid')| markdownInline }}` |

## Gallery alt text

The v1 `Featured` / `First` / `Last` macros become the [dynamic selectors](docs/twig/media#galleries) `'featured'`, `'first'`, `'last'` (and `'random'`) passed to `galleryAlt()`.

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsGalleryImageFeaturedAlt(cmsid)%` | Featured image alt | `{{ cms.render.galleryAlt('cmsid', 'featured') }}` |
| `%cmsGalleryImageFeaturedAltFormat(cmsid)%` | Featured alt (markdown) | `{{ cms.render.galleryAlt('cmsid', 'featured')| markdownInline }}` |
| `%cmsGalleryImageFirstAlt(cmsid)%` | First image alt | `{{ cms.render.galleryAlt('cmsid', 'first') }}` |
| `%cmsGalleryImageFirstAltFormat(cmsid)%` | First alt (markdown) | `{{ cms.render.galleryAlt('cmsid', 'first')| markdownInline }}` |
| `%cmsGalleryImageLastAlt(cmsid)%` | Last image alt | `{{ cms.render.galleryAlt('cmsid', 'last') }}` |
| `%cmsGalleryImageLastAltFormat(cmsid)%` | Last alt (markdown) | `{{ cms.render.galleryAlt('cmsid', 'last')| markdownInline }}` |

## Image path macros

In v1, `Thumb` and `Square` were fixed-size presets. In T3 you request **any** size through ImageWorks, so the examples below are starting points — pick the dimensions your layout needs. `Square` is simply an equal width/height with `fit: 'crop'`; PNG output is `fm: 'png'`.

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsImage(cmsid)%` | Full image URL | `{{ cms.media.imagePath('cmsid') }}` |
| `%cmsImageThumb(cmsid)%` | Thumbnail | `{{ cms.media.imagePath('cmsid', {w: 300}) }}` |
| `%cmsImageSquare(cmsid)%` | Square crop | `{{ cms.media.imagePath('cmsid', {w: 300, h: 300, fit: 'crop'}) }}` |
| `%cmsImagePng(cmsid)%` | Full image (PNG) | `{{ cms.media.imagePath('cmsid', {fm: 'png'}) }}` |
| `%cmsImagePngThumb(cmsid)%` | Thumbnail (PNG) | `{{ cms.media.imagePath('cmsid', {w: 300, fm: 'png'}) }}` |
| `%cmsImagePngSquare(cmsid)%` | Square crop (PNG) | `{{ cms.media.imagePath('cmsid', {w: 300, h: 300, fit: 'crop', fm: 'png'}) }}` |

> `cms.media.imagePath()` returns a **URL** for use in `src`/`url()`. To emit a complete `<img>` tag with alt text and lazy loading, use [`cms.render.image('cmsid', {w: 300})`](docs/twig/render#images) instead.

## Gallery path macros

The `Featured` / `First` / `Last` / `Random` variants all collapse into a single `galleryPath()` call with the matching selector. Apply the same `Thumb` / `Square` sizing options shown above.

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsGalleryImageFeatured(cmsid)%` | Featured image | `{{ cms.media.galleryPath('cmsid', 'featured') }}` |
| `%cmsGalleryImageFeaturedThumb(cmsid)%` | Featured thumbnail | `{{ cms.media.galleryPath('cmsid', 'featured', {w: 300}) }}` |
| `%cmsGalleryImageFeaturedSquare(cmsid)%` | Featured square | `{{ cms.media.galleryPath('cmsid', 'featured', {w: 300, h: 300, fit: 'crop'}) }}` |
| `%cmsGalleryImageFirst(cmsid)%` | First image | `{{ cms.media.galleryPath('cmsid', 'first') }}` |
| `%cmsGalleryImageFirstThumb(cmsid)%` | First thumbnail | `{{ cms.media.galleryPath('cmsid', 'first', {w: 300}) }}` |
| `%cmsGalleryImageFirstSquare(cmsid)%` | First square | `{{ cms.media.galleryPath('cmsid', 'first', {w: 300, h: 300, fit: 'crop'}) }}` |
| `%cmsGalleryImageLast(cmsid)%` | Last image | `{{ cms.media.galleryPath('cmsid', 'last') }}` |
| `%cmsGalleryImageLastThumb(cmsid)%` | Last thumbnail | `{{ cms.media.galleryPath('cmsid', 'last', {w: 300}) }}` |
| `%cmsGalleryImageLastSquare(cmsid)%` | Last square | `{{ cms.media.galleryPath('cmsid', 'last', {w: 300, h: 300, fit: 'crop'}) }}` |
| `%cmsGalleryImageRandom(cmsid)%` | Random image | `{{ cms.media.galleryPath('cmsid', 'random') }}` |
| `%cmsGalleryImageRandomThumb(cmsid)%` | Random thumbnail | `{{ cms.media.galleryPath('cmsid', 'random', {w: 300}) }}` |
| `%cmsGalleryImageRandomSquare(cmsid)%` | Random square | `{{ cms.media.galleryPath('cmsid', 'random', {w: 300, h: 300, fit: 'crop'}) }}` |

> To render a whole gallery with a lightbox (rather than one image URL), reach for [`cms.render.gallery('cmsid')`](docs/twig/render#galleries).

## File & download macros

Use `stream()` for an inline URL (viewing in the browser, range requests for video/audio) and `download()` to force a save dialog. Note that v1 addressed files by `filename.ext`; T3 addresses them by object **id**.

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsFile(cmsid.ext)%` | File URL (inline) | `{{ cms.media.stream('cmsid') }}` |
| `%cmsFileDownload(cmsid.ext)%` | File download URL | `{{ cms.media.download('cmsid') }}` |

## DataStore

The v1 **DataStore** macros (`%cmsDataStore%`, `%cmsDataStoreDownload%`) backed a form that appended submissions to a CSV file. **There is no direct equivalent in Total CMS 3** — the CSV-backed form no longer exists in that form.

Migrate the pattern like this:

1. **Create a custom collection** with a schema matching your form fields.
2. **Build a form** that saves submissions into that collection with the [Form Builder](docs/forms/builder) — each submission becomes an object instead of a CSV row.
3. **Export to CSV** when you need the flat file: use [collection export](docs/collections/export) in the admin, or `tcms collection:export` from the [CLI](docs/extensions/cli).

This gives you everything the old DataStore did (capture form data, download it as CSV) plus queryable, editable records in the admin.

## Before & after

A typical v1 page fragment:

```html
<h1>%cmsText(headline)%</h1>
<div>%cmsTextFormat(intro)%</div>
<img src="%cmsImageSquare(team)%" alt="%cmsImageAlt(team)%">
<a href="%cmsFileDownload(brochure.pdf)%">Download the brochure</a>
```

The same fragment in a Total CMS 3 Twig template:

```twig
<h1>{{ cms.data.text('headline') }}</h1>
<div>{{ cms.data.text('intro')|markdown }}</div>
{{ cms.render.image('team', {w: 300, h: 300, fit: 'crop'}) }}
<a href="{{ cms.media.download('brochure') }}">Download the brochure</a>
```

Note how `cms.render.image()` replaces the paired `%cmsImageSquare%` + `%cmsImageAlt%` macros with a single call that emits the sized `<img>` tag and its alt text together.
