---
title: "Migrating from Total CMS v1"
description: "Everything you need to move a Total CMS v1 site to Total CMS 3: the built-in v1 importer, what it brings over, rethinking repurposed blogs as custom schemas, and a full mapping of every v1 page macro to its Twig equivalent."
related:
  - twig/data
  - twig/media
  - twig/render
  - collections/export
  - forms/builder
---

# Migrating from Total CMS v1

Total CMS 3 is a ground-up rewrite, so a v1 site moves over in two parts:

1. **Your content** — the data in `cms-data/` (blogs, galleries, images, files, dates, feeds). Total CMS 3 ships a built-in importer that brings this across for you.
2. **Your templates** — the `%macro(cmsid)%` tags scattered through your v1 HTML become `{{ cms... }}` calls inside [Twig templates](docs/twig/overview). The [macro mapping table](#update-your-templates-v1-macros--twig) below covers every one.

This page is the one-stop guide for both. Read it top to bottom before you start a migration.

## You probably don't need a custom admin

In Total CMS v1 you had to **build your own admin pages** to manage content. You can still do that in Total CMS 3 if you want a fully custom admin area — the API and Twig layer make it entirely possible.

But you rarely need to. Total CMS 3 includes a complete, pre-built admin with a form builder, collections, media handling, and user management out of the box. For the vast majority of sites the built-in admin saves many hours of work, and a custom admin is reserved for genuinely special cases. **Start with the built-in admin** — only reach for a custom one if you hit a wall.

## Import your content

Total CMS 3 has a dedicated **Total CMS 1 importer**. In the admin go to **Utilities → Import Total CMS 1**. It auto-detects a `cms-data/` folder (or you can type the path manually), shows you what it found, and queues everything for import. Migrated items run through the job queue — watch progress under **Utilities → Job Queue**.

> **The importer never deletes your `cms-data/` folder.** It reads from it and leaves the originals untouched, so you can re-run the import safely.

### What the importer brings over

Your v1 `cms-data/` is organized into folders by content type. The importer maps each one into a Total CMS 3 collection. Folders only exist for the features you actually used, so expect to see a subset of this:

| Total CMS v1 folder | Becomes in Total CMS 3 | Notes |
|---------------------|------------------------|-------|
| `blog/<name>/` | A collection per blog (id = the folder name) | Uses the special **`blog-legacy`** schema for v1 compatibility. The blog's `.posturl` becomes the collection URL (pretty URLs are detected automatically). |
| `date/` | The `date` collection | v1 stored dates as Unix timestamps; the importer converts them to ISO‑8601. |
| `depot/<name>/` | Objects in the `depot` collection | One object per depot folder. |
| `feed/<name>/` | A collection per feed | The `.cms` filename becomes the object id and title; the file contents become the body. |
| `file/` | The `file` collection | One object per file, addressed by id. |
| `gallery/<name>/` | Objects in the `gallery` collection | The `blog/` and `feed-*` gallery folders are skipped — they belong to blogs and feeds and are handled with those records. |
| `image/` | The `image` collection | One object per image, addressed by id. |
| `text/` | The `text` collection | One object per text snippet. |
| `video/` | The `url` collection | The stored video URL becomes the object's `url`. |

Images and galleries are **re-processed** on import — Total CMS 3 regenerates its own metadata, color palettes, and resized variants from your original files, so you don't carry v1's derived files across.

### Images inside styled text aren't imported automatically

There is one important exception. Images that were **uploaded directly into a v1 styled‑text area** are *not* migrated by the importer. To keep those images working:

- **Keep your `cms-data/` folder in place** on the server. The existing image URLs inside that styled text will continue to resolve from it.
- Or **manually re-add** the images into the styled text in Total CMS 3 (uploading them through the editor) and then remove the dependency on `cms-data/`.

## Rethink "abused" blogs as custom schemas

In Total CMS v1 the blog was the only thing resembling a collection, so people **repurposed blogs to build everything** — online stores, directories, event listings, you name it. The trick was to reuse the fixed blog fields for other meanings: *"the `author` field is really my price, the `category` is really the brand."* It worked, but you had to remember which field meant what.

Total CMS 3 removes that constraint: you can **define a custom schema** with properties named for exactly what they hold (`price`, `brand`, `sku`). Migrating a repurposed blog into a proper custom collection is well worth the effort — your data finally matches its labels, and the admin forms make sense.

Recommended workflow:

1. **Export the v1 blog to CSV** — import it as a `blog-legacy` collection (above), then use [collection export](docs/collections/export) to get a CSV.
2. **Rename the CSV headers** to your new field names (`author` → `price`, `category` → `brand`, …).
3. **Create your custom collection** with a matching [schema](docs/schemas/reference), then **import the CSV** into it.

You end up with a clean, purpose-built collection instead of a blog wearing a disguise.

## Update your templates: v1 macros → Twig

Total CMS v1 injected content into a page with `%macro(cmsid)%` tags placed directly in your HTML. Total CMS 3 has no macro processor — content is pulled into [Twig templates](docs/twig/overview) through the global `cms` variable instead. This section maps every v1 macro to its Total CMS 3 Twig equivalent so you can update your templates field by field.

### How the model changed

| Concept | Total CMS v1 | Total CMS 3 |
|---------|--------------|-------------|
| **Where tags live** | `%cmsText(headline)%` placed anywhere in the page HTML | `{{ cms.data.text('headline') }}` inside a `.twig` template |
| **Content addressing** | A single global `cmsid` per content type | A **collection** + object **id**. The reserved collections (`text`, `image`, `gallery`, `file`, `toggle`, `date`, …) are the defaults, so a lone `cmsid` still maps 1:1 |
| **Image sizes** | Fixed `Thumb` / `Square` presets baked into the macro name | Any dimensions on demand via [ImageWorks](docs/twig/imageworks) options — `{w: 300, h: 300, fit: 'crop'}` |
| **PNG output** | Separate `…Png…` macros | One option: `{fm: 'png'}` |
| **Markdown / formatting** | Separate `…Format` macros | A Twig filter: `| markdown` or `| markdownInline` |

Because the reserved-collection defaults match v1's content types, the `cmsid` you used in v1 becomes the object **id** in T3 with no extra arguments. If you migrated content into a *custom* collection, add a context object: `{collection: 'pages', property: 'body'}`.

### Text macros

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsData(cmsid)%` | Raw stored value | `{{ cms.data('text', 'cmsid', 'text') }}` |
| `%cmsText(cmsid)%` | Text | `{{ cms.data.text('cmsid') }}` |
| `%cmsTextFormat(cmsid)%` | Markdown → HTML | `{{ cms.data.text('cmsid')| markdown }}` |
| `%cmsTextStripHTML(cmsid)%` | Strip HTML | `{{ cms.data.text('cmsid')| striptags }}` |
| `%cmsToggle(cmsid)%` | Boolean toggle | `{{ cms.data.toggle('cmsid') }}` |

> `cms.data('text', 'cmsid', 'text')` is the [callable shorthand](docs/twig/data#raw-data-access) for `cms.data.raw(...)` — it returns the value exactly as stored. For everyday text use `cms.data.text('cmsid')`.

### Image alt text

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsImageAlt(cmsid)%` | Image alt | `{{ cms.render.alt('cmsid') }}` |
| `%cmsImageAltFormat(cmsid)%` | Image alt (markdown) | `{{ cms.render.alt('cmsid')| markdownInline }}` |

### Gallery alt text

The v1 `Featured` / `First` / `Last` macros become the [dynamic selectors](docs/twig/media#galleries) `'featured'`, `'first'`, `'last'` (and `'random'`) passed to `galleryAlt()`.

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsGalleryImageFeaturedAlt(cmsid)%` | Featured image alt | `{{ cms.render.galleryAlt('cmsid', 'featured') }}` |
| `%cmsGalleryImageFeaturedAltFormat(cmsid)%` | Featured alt (markdown) | `{{ cms.render.galleryAlt('cmsid', 'featured')| markdownInline }}` |
| `%cmsGalleryImageFirstAlt(cmsid)%` | First image alt | `{{ cms.render.galleryAlt('cmsid', 'first') }}` |
| `%cmsGalleryImageFirstAltFormat(cmsid)%` | First alt (markdown) | `{{ cms.render.galleryAlt('cmsid', 'first')| markdownInline }}` |
| `%cmsGalleryImageLastAlt(cmsid)%` | Last image alt | `{{ cms.render.galleryAlt('cmsid', 'last') }}` |
| `%cmsGalleryImageLastAltFormat(cmsid)%` | Last alt (markdown) | `{{ cms.render.galleryAlt('cmsid', 'last')| markdownInline }}` |

### Image path macros

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

### Gallery path macros

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

### File & download macros

Use `stream()` for an inline URL (viewing in the browser, range requests for video/audio) and `download()` to force a save dialog. Note that v1 addressed files by `filename.ext`; T3 addresses them by object **id**.

| Total CMS v1 | Description | Total CMS 3 Twig |
|--------------|-------------|------------------|
| `%cmsFile(cmsid.ext)%` | File URL (inline) | `{{ cms.media.stream('cmsid') }}` |
| `%cmsFileDownload(cmsid.ext)%` | File download URL | `{{ cms.media.download('cmsid') }}` |

### DataStore

The v1 **DataStore** macros (`%cmsDataStore%`, `%cmsDataStoreDownload%`) backed a form that appended submissions to a CSV file. **There is no direct equivalent in Total CMS 3** — the CSV-backed form no longer exists in that form.

Migrate the pattern like this:

1. **Create a custom collection** with a schema matching your form fields.
2. **Build a form** that saves submissions into that collection with the [Form Builder](docs/forms/builder) — each submission becomes an object instead of a CSV row.
3. **Export to CSV** when you need the flat file: use [collection export](docs/collections/export) in the admin, or `tcms collection:export` from the [CLI](docs/extensions/cli).

This gives you everything the old DataStore did (capture form data, download it as CSV) plus queryable, editable records in the admin.

### Blog post page macros

Total CMS v1 blog post pages had their own large family of `%blogXxx()%` macros that pulled fields from "the current post." Total CMS 3 has no implicit current post — you **load the post object once** at the top of the template, then read its fields like any other object.

```twig
{# Load the post for the id in the URL (e.g. /blog/my-post) #}
{% set post = cms.collection.object('blog', getData.id) %}

<h1>{{ post.title }}</h1>
```

> On a [Site Builder](docs/site-builder/overview) blog page the matched record is already provided as `object`, so you can skip the `set` and use `object.*` directly. Everything below works the same either way — just swap `post` for your variable name.

Migrated blogs use the **`blog-legacy`** schema, whose property names are shown below. (The v1 `permalink` becomes the object **id**.)

#### Text fields

| Total CMS v1 | Total CMS 3 Twig |
|--------------|------------------|
| `%blogPermalink()%` | `{{ post.id }}` |
| `%blogTitle()%` | `{{ post.title }}` |
| `%blogAuthor()%` | `{{ post.author }}` |
| `%blogGenre()%` | `{{ post.genre }}` |
| `%blogContent()%` | `{{ post.content }}` |
| `%blogSummary()%` | `{{ post.summary }}` |
| `%blogExtraContent()%` | `{{ post.extra }}` |
| `%blogExtraContent2()%` | `{{ post.extra2 }}` |
| `%blogMedia()%` | `{{ post.media }}` |
| `%blogTags()%` | `{{ post.tags| join(', ') }}` |
| `%blogCategories()%` | `{{ post.categories| join(', ') }}` |
| `%blogLabels()%` | `{{ post.labels| join(', ') }}` |

The v1 `…Format` and `…StripHTML` variants are just Twig filters on the same field — append `| markdown` (or `| markdownInline`) for the formatted version and `| striptags` to strip HTML. For example `%blogContentFormat()%` → `{{ post.content| markdown }}` and `%blogContentStripHTML()%` → `{{ post.content| striptags }}`. The `tags` / `categories` / `labels` fields are lists, so render them with `| join(', ')` (or loop over them).

#### Date fields

The post's `date` is stored as ISO‑8601; format it with Twig's [`date`](docs/twig/filters) filter.

| Total CMS v1 | Output | Total CMS 3 Twig |
|--------------|--------|------------------|
| `%blogDateMonth()%` | `01`–`12` | `{{ post.date| date('m') }}` |
| `%blogDateMonthName()%` | `January`–`December` | `{{ post.date| date('F') }}` |
| `%blogDateMonthNameShort()%` | `Jan`–`Dec` | `{{ post.date| date('M') }}` |
| `%blogDateDay()%` | `01`–`31` | `{{ post.date| date('d') }}` |
| `%blogDateDayName()%` | `Monday`–`Sunday` | `{{ post.date| date('l') }}` |
| `%blogDateDayNameShort()%` | `Mon`–`Sun` | `{{ post.date| date('D') }}` |
| `%blogDateYear()%` | `2017` | `{{ post.date| date('Y') }}` |
| `%blogDateYearShort()%` | `17` | `{{ post.date| date('y') }}` |
| `%blogDateISO8601()%` | `2022-06-04T00:50:29+00:00` | `{{ post.date| date('c') }}` |

#### Images & galleries

A blog post has two media fields: a single **`image`** and a **`gallery`**. The plain `%blogImage…%` macros read the `image` field; the `Featured` / `First` / `Last` / `Random` variants are gallery selectors. Pass the loaded `post` object plus the field context.

| Total CMS v1 | Total CMS 3 Twig |
|--------------|------------------|
| `%blogImage()%` | `{{ cms.media.imagePath(post, {}, {collection: 'blog', property: 'image'}) }}` |
| `%blogImageThumb()%` | `{{ cms.media.imagePath(post, {w: 300}, {collection: 'blog', property: 'image'}) }}` |
| `%blogImageSquare()%` | `{{ cms.media.imagePath(post, {w: 300, h: 300, fit: 'crop'}, {collection: 'blog', property: 'image'}) }}` |
| `%blogImageAlt()%` | `{{ cms.render.alt(post, {collection: 'blog', property: 'image'}) }}` |
| `%blogImageFeatured()%` | `{{ cms.media.galleryPath(post, 'featured', {}, {collection: 'blog', property: 'gallery'}) }}` |
| `%blogImageFirst()%` | `{{ cms.media.galleryPath(post, 'first', {}, {collection: 'blog', property: 'gallery'}) }}` |
| `%blogImageLast()%` | `{{ cms.media.galleryPath(post, 'last', {}, {collection: 'blog', property: 'gallery'}) }}` |
| `%blogImageRandom()%` | `{{ cms.media.galleryPath(post, 'random', {}, {collection: 'blog', property: 'gallery'}) }}` |
| `%blogImageFeaturedAlt()%` | `{{ cms.render.galleryAlt(post, 'featured', {collection: 'blog', property: 'gallery'}) }}` |

Apply the same `{w: 300}` / `{w: 300, h: 300, fit: 'crop'}` sizing options shown for `image` to any gallery selector (the v1 `…Thumb` / `…Square` variants), and the same `First` / `Last` / `Random` selectors to the alt calls. To emit a complete `<img>` tag (URL + dimensions + alt + lazy loading) instead of a bare URL, use [`cms.render.image(post, {w: 300}, {collection: 'blog', property: 'image'})`](docs/twig/render#images); to render the whole gallery with a lightbox, use [`cms.render.gallery(post)`](docs/twig/render#galleries).

### Before & after

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
