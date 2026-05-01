---
title: "Twig Media Reference"
description: "Reference for the cms.media namespace providing image paths, gallery paths, file downloads, and streaming URLs."
---

# cms.media

The media adapter provides URL generation for images (with ImageWorks transformations), gallery images, file downloads, and streaming. For rendering images as HTML, see [cms.render](docs/twig/render).

## Images

Image functions take **three separate arguments**: the object, image transform options, and collection context. These are separate objects — do not merge them.

```twig
cms.media.imagePath(object, {transforms}, {context})
                     │         │              │
                     │         │              └── collection, property
                     │         └── w, h, fit, fm, etc.
                     └── object ID or full object
```

### imagePath()

Get the ImageWorks API URL for an image. Use this when you need just the URL (not a full `<img>` tag).

```twig
{# Basic image URL #}
<img src="{{ cms.media.imagePath('hero') }}" alt="Hero">

{# With ImageWorks transformations #}
<img src="{{ cms.media.imagePath('hero', {w: 800, h: 600, fit: 'crop'}) }}">

{# WebP format #}
<div style="background-image: url('{{ cms.media.imagePath('bg', {w: 1920, fm: 'webp'}) }}')"></div>

{# Custom collection and property — note: separate argument from transforms #}
{{ cms.media.imagePath('widget', {w: 400}, {collection: 'products', property: 'photo'}) }}

{# Pass object directly (avoids re-fetching) #}
{{ cms.media.imagePath(product, {w: 600}, {collection: 'products', property: 'image'}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array\|null | required | Object ID or full object data |
| `imageworks` | array | `[]` | ImageWorks transformation parameters (`w`, `h`, `fit`, `fm`, etc.) |
| `options` | array | `[]` | Collection context: `collection`, `property` |

For available ImageWorks parameters, see the [ImageWorks Reference](docs/twig/imageworks).

### Nested images (cards and decks)

Images stored inside a `card` or `deck` field are addressed through the `property` option using a **dot-notation path**. The first segment is the parent property; subsequent segments walk down to the image.

```twig
{# Card child — `mycard` is the card field, `image` is the child key #}
{{ cms.media.imagePath('post-1', {}, {property: 'mycard.image'}) }}

{# Deck child — `mydeck` is the deck field, `item-3` is the deck-item id, `image` is the child key #}
{{ cms.media.imagePath('post-1', {}, {property: 'mydeck.item-3.image'}) }}

{# Same syntax works when passing the object directly #}
{{ cms.media.imagePath(post, {w: 800}, {property: 'mycard.image'}) }}
```

The dot-notation maps directly to URL segments — `mycard.image` becomes `/api/imageworks/{coll}/{id}/mycard/image.{format}` — and the image data is resolved by descending the same path on the object (`obj.mycard.image`).

The same syntax works on the corresponding render macro:

```twig
{{ cms.render.image('post-1', {w: 800}, {property: 'mycard.image'}) }}
```

The Image Builder dialog (the imageworks utility opened from the admin form) detects nested images and shows the dotted-property macro in its "Copy macro" section, so you can copy a working snippet directly.

## Galleries

### galleryPath()

Get the ImageWorks API URL for a specific gallery image.

```twig
{# By filename #}
<img src="{{ cms.media.galleryPath('vacation', 'sunset.jpg', {w: 800}) }}">

{# Dynamic selectors #}
<img src="{{ cms.media.galleryPath('vacation', 'first', {w: 400}) }}">
<img src="{{ cms.media.galleryPath('vacation', 'featured', {w: 400}) }}">

{# Custom collection #}
{{ cms.media.galleryPath('widget', 'front.jpg', {w: 300}, {collection: 'products'}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array\|null | required | Object ID or full object data |
| `name` | string\|int\|null | required | Filename, index, or dynamic selector (`first`, `last`, `random`, `featured`) |
| `imageworks` | array | `[]` | ImageWorks transformation parameters |
| `options` | array | `[]` | Options: `collection`, `property` |

### galleryImageData()

Get the complete image data object for a specific gallery image. Returns `null` if not found.

```twig
{% set imgData = cms.media.galleryImageData('vacation', 'sunset.jpg') %}
{% if imgData %}
    <p>{{ imgData.alt }}</p>
    <p>{{ imgData.width }}x{{ imgData.height }}</p>
{% endif %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array | required | Object ID or full object data |
| `name` | string\|int | required | Filename or index |
| `options` | array | `[]` | Options: `collection`, `property` |

## File Downloads

Downloads use `Content-Disposition: attachment` to force the browser download dialog.

### download()

Get the download URL for a file property.

```twig
<a href="{{ cms.media.download('report') }}">Download Report</a>

{# Custom collection and property #}
<a href="{{ cms.media.download('doc', {collection: 'documents', property: 'pdf'}) }}">Download PDF</a>

{# Password-protected #}
<a href="{{ cms.media.download('doc', {pwd: 'secret123'}) }}">Download</a>
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array | required | Object ID or full object data |
| `options` | array | `[]` | Options: `collection`, `property`, `pwd` |

### depotDownload()

Get the download URL for a specific file in a depot.

```twig
{% set files = cms.media.depot('documents') %}
{% for file in files %}
    <a href="{{ cms.media.depotDownload('documents', file.name) }}">{{ file.name }}</a>
{% endfor %}

{# File in a subfolder #}
{{ cms.media.depotDownload('docs', 'report.pdf', {path: 'reports/2024'}) }}
{{ cms.media.depotDownload('docs', 'reports/2024/report.pdf') }}

{# Password-protected #}
{{ cms.media.depotDownload('docs', 'secret.zip', {pwd: 'pass123'}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array | required | Object ID or full object data |
| `name` | string | required | Filename |
| `options` | array | `[]` | Options: `collection`, `property`, `path`, `pwd` |

## File Streaming

Streaming uses `Content-Disposition: inline` and supports HTTP range requests, making it ideal for video and audio playback.

### stream()

Get the stream URL for a file property.

```twig
<video controls>
    <source src="{{ cms.media.stream('intro-video') }}" type="video/mp4">
</video>

<audio controls>
    <source src="{{ cms.media.stream('podcast') }}" type="audio/mpeg">
</audio>

{# Custom collection #}
{{ cms.media.stream('clip', {collection: 'videos', property: 'video'}) }}

{# Password-protected #}
{{ cms.media.stream('clip', {pwd: 'secret123'}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array | required | Object ID or full object data |
| `options` | array | `[]` | Options: `collection`, `property`, `pwd` |

### depotStream()

Get the stream URL for a specific file in a depot.

```twig
<video controls>
    <source src="{{ cms.media.depotStream('media', 'intro.mp4') }}" type="video/mp4">
</video>

{# File in a subfolder #}
{{ cms.media.depotStream('media', 'movie.mp4', {path: 'videos'}) }}
{{ cms.media.depotStream('media', 'videos/movie.mp4') }}

{# Password-protected #}
{{ cms.media.depotStream('media', 'audio.mp3', {pwd: 'pass123'}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array | required | Object ID or full object data |
| `name` | string | required | Filename |
| `options` | array | `[]` | Options: `collection`, `property`, `path`, `pwd` |

## Depot File Listing

### depot()

Get the list of files in a depot property.

```twig
{% set files = cms.media.depot('documents') %}
{% for file in files %}
    <p>{{ file.name }} ({{ file.size }} bytes)</p>
{% endfor %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | string | required | Object identifier |
| `options` | array | `[]` | Options: `collection`, `property` |

## Stream vs Download

| | Stream | Download |
|---|---|---|
| **Content-Disposition** | `inline` | `attachment` |
| **Range requests** | Yes | No |
| **Best for** | Video, audio, PDFs in-browser | Files to save locally |
| **Password support** | Yes | Yes |
