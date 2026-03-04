---
title: "Total CMS Twig Adapter"
description: "Overview of the cms global variable in Twig templates with links to per-namespace documentation."
---

# Total CMS Twig Adapter

The Total CMS Twig Adapter provides access to all CMS data and functionality through the global `cms` variable in Twig templates. Methods are organized into namespaces for clarity.

## Namespace Reference

| Namespace | Description | Documentation |
|-----------|-------------|---------------|
| `cms.admin.*` | Dashboard stats, job queue, dev mode, URL helpers | [Admin Reference](docs/twig/admin) |
| `cms.auth.*` | Login/logout, user info, access control, passkeys | [Auth Reference](docs/twig/auth) |
| `cms.barcode.*` | Barcode generation (Code 128, EAN-13, UPC-A, etc.) | [Barcode Reference](docs/twig/barcodes) |
| `cms.collection.*` | Collection listing, objects, search, URLs, navigation | [Collections Reference](docs/twig/collections) |
| `cms.data.*` | Typed data access (text, toggle, date, color, etc.) | [Data Reference](docs/twig/data) |
| `cms.edition.*` | Edition detection and feature gating | [Edition Reference](docs/twig/edition) |
| `cms.locale.*` | Localization and translations | [Locale Reference](docs/twig/locale) |
| `cms.media.*` | Image paths, gallery paths, downloads, streaming | [Media Reference](docs/twig/media) |
| `cms.qrcode.*` | QR code generation | [QR Code Reference](docs/twig/qrcodes) |
| `cms.render.*` | HTML rendering for images, galleries, pagination | [Render Reference](docs/twig/render) |
| `cms.schema.*` | Schema listing, fetching, inheritance, decks | [Schemas Reference](docs/twig/schemas) |
| `cms.view.*` | Pre-computed data views | [Views Reference](docs/twig/views) |

## Root-Level Properties

These properties are accessed directly on `cms` without a namespace.

```twig
{{ cms.env }}                    {# Current environment (development, production) #}
{{ cms.api }}                    {# API base URL #}
{{ cms.dashboard }}              {# Admin dashboard URL #}
{{ cms.domain }}                 {# Current domain name #}
{{ cms.clearcache }}             {# Emergency cache clear URL #}
{{ cms.currentUrl }}             {# Current request URI #}
{{ cms.version }}                {# Version information object #}
```

### config()

Get a configuration value by key, with optional nested setting.

```twig
{{ cms.config('debug') }}
{{ cms.config('key', 'setting') }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `key` | string | required | Top-level config key |
| `setting` | string\|null | `null` | Nested setting within the key |

### log()

Log messages from templates. Written to `twig.log` and viewable in the admin log analyzer.

```twig
{{ cms.log('Something unexpected happened') }}
{{ cms.log('Missing image for product', 'error') }}
{{ cms.log('Debug info', 'debug', {id: object.id}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `message` | string | required | The message to log |
| `level` | string | `'warning'` | Log level: `debug`, `info`, `warning`, `error` |
| `context` | object | `{}` | Additional context data |

## ImageWorks Parameters

These parameters are used with image transformation methods across `cms.media` and `cms.render`:

### Basic Image Controls
- `w` - Width in pixels
- `h` - Height in pixels
- `fit` - How to fit image: `contain`, `max`, `fill`, `stretch`, `crop`
- `crop` - Crop position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`
- `fm` - Output format: `jpg`, `png`, `gif`, `webp`, `avif`
- `q` - Quality (1-100)

### Effects & Filters
- `blur` - Blur amount (0-100)
- `sharp` - Sharpen amount (0-100)
- `pixel` - Pixelate amount (0-100)
- `filt` - Filter: `greyscale`, `sepia`

### Image Watermarks
- `mark` - Watermark image path
- `markw` - Watermark width
- `markh` - Watermark height
- `markpos` - Watermark position
- `markpad` - Watermark padding
- `markalpha` - Watermark opacity (0-100)

### Text Watermarks
- `marktext` - Text to display as watermark
- `marktextfont` - Font family name (TTF/OTF fonts from watermark-fonts depot)
- `marktextsize` - Text size in pixels (default: 500)
- `marktextcolor` - Text color as hex (without #, e.g., 'ffffff' for white)
- `marktextbg` - Background color as hex (optional, transparent if not set)
- `marktextpad` - Padding around text in pixels (default: 10)
- `marktextangle` - Text rotation angle in degrees (-360 to 360, default: 0)
- `marktextpos` - Text position: `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`
- `marktextw` - Maximum text width in pixels or relative (e.g., '50w' for 50% of image width)
- `marktextalpha` - Text transparency (0-100, where 100 is fully opaque)

## Gallery Captions

Galleries support two independent caption options:

- **`captions`** - Captions inside the lightbox overlay
- **`gridCaptions`** - Captions below thumbnails in the grid

Both accept either `true` for default behavior or a **Twig template string** for custom formatting.

### Default Captions

When set to `true`, captions use this fallback chain: alt text → EXIF title → EXIF description. If none are available, no caption is shown. Filenames are never used as captions.

```twig
{{ cms.render.gallery('id', {w: 300}, {w: 1500}, {
    captions: true,
    gridCaptions: true
}) }}
```

### Caption Templates

Pass a template string to customize caption content. Use single curly braces `{variable}` for template variables — they are automatically converted to Twig syntax before rendering.

```twig
{# Simple alt text caption #}
{% set caption = "{alt}" %}
{{ cms.render.gallery('id', {}, {}, {captions: caption}) }}

{# Photography captions with EXIF data #}
{% set caption %}
<h4>{alt}</h4>
<p>{exif.camera} · {exif.lens}</p>
<p>f/{exif.aperture} · {exif.shutterSpeed} · ISO {exif.iso}</p>
{% endset %}
{{ cms.render.gallery('id', {}, {}, {captions: caption}) }}

{# Different templates for grid and lightbox #}
{% set gridCaption = "{alt}" %}
{% set lightboxCaption %}
<h4>{alt}</h4>
<p>{exif.description}</p>
<p>{exif.camera} — f/{exif.aperture}</p>
{% endset %}

{{ cms.render.gallery('id', {w: 300}, {w: 1500}, {
    gridCaptions: gridCaption,
    captions: lightboxCaption
}) }}
```

When a template string is used, HTML is preserved (not escaped). When set to `true`, plain text captions are HTML-escaped. If all template variables resolve to empty, the caption is suppressed entirely.

### Available Caption Variables

**Image fields:**

| Variable | Description |
|----------|-------------|
| `alt` | Alt text |
| `name` | Filename |
| `width` | Image width in pixels |
| `height` | Image height in pixels |
| `mime` | MIME type |
| `size` | File size in bytes |
| `link` | Optional link URL |
| `tags` | Array of tags |
| `uploadDate` | Upload date (ISO 8601) |

**EXIF metadata** (available when the image contains EXIF data):

| Variable | Description |
|----------|-------------|
| `exif.title` | Image title |
| `exif.description` | Image description |
| `exif.camera` | Camera model |
| `exif.make` | Camera manufacturer |
| `exif.lens` | Lens model |
| `exif.aperture` | f-number (e.g., 1.8) |
| `exif.shutterSpeed` | Shutter speed (e.g., 1/125) |
| `exif.iso` | ISO sensitivity |
| `exif.focalLength` | Focal length in mm |
| `exif.date` | Photo capture date |
| `exif.author` | Photographer name |
| `exif.copyright` | Copyright notice |
| `exif.city` | City |
| `exif.state` | State/Province |
| `exif.country` | Country |
| `exif.sublocation` | Specific location |
| `exif.latitude` | GPS latitude |
| `exif.longitude` | GPS longitude |
| `exif.altitude` | GPS altitude |

## Sorting Gallery Images

By default, gallery images display in their stored order. Use the `sort` option with both `cms.render.gallery()` and `cms.render.galleryLauncher()`.

```twig
{# Simple sort #}
{{ cms.render.gallery('id', {}, {}, {sort: 'name'}) }}
{{ cms.render.gallery('id', {}, {}, {sort: 'uploadDate'}) }}

{# Descending (prefix with -) #}
{{ cms.render.gallery('id', {}, {}, {sort: '-exif.date'}) }}

{# Multi-criteria sort #}
{{ cms.render.gallery('id', {}, {}, {sort: [
    {property: 'featured', reverse: true},
    {property: 'exif.date', reverse: true}
]}) }}
```

**Sortable Properties:**

| Property | Description |
|----------|-------------|
| `name` | Filename (natural sort) |
| `uploadDate` | Upload date (ISO 8601) |
| `size` | File size in bytes |
| `width` | Image width in pixels |
| `height` | Image height in pixels |
| `featured` | Boolean — useful for sorting featured images first |
| `exif.date` | Date photo was taken |
| `exif.camera` | Camera model |
| `exif.focalLength` | Focal length |
| `exif.aperture` | Aperture value |
| `exif.iso` | ISO sensitivity |

## Examples

### Display a blog post
```twig
{% set post = cms.collection.object('blog', 'my-post-id') %}
<article>
    <h1>{{ post.title }}</h1>
    <time>{{ post.date|dateRelative }}</time>
    {{ post.content|markdown }}
    {{ cms.render.image(post.id, {w: 800, h: 400, fit: 'crop'}) }}
</article>
```

### Create an image gallery
```twig
{% set product = cms.collection.object('products', 'widget-pro') %}
<div class="product-gallery">
    {{ cms.render.gallery(product.id, {w: 100, h: 100}, {w: 1200}, {
        maxVisible: 4,
        viewAllText: 'View all images'
    }) }}
</div>
```

### Gallery launcher with custom triggers
```twig
{% set product = cms.collection.object('products', 'widget-pro') %}

{{ cms.render.galleryLauncher(product.id, {w: 300, h: 300}, {w: 1920}, {
    captions: true,
    trigger: '.product-thumb',
    plugins: ['zoom', 'fullscreen']
}) }}

<div class="product-images">
    <img class="product-thumb main-image"
         data-gallery-image="front.jpg"
         src="{{ cms.media.galleryPath(product.id, 'front.jpg', {w: 600, h: 400}) }}">

    <div class="thumbnail-strip">
        <img class="product-thumb"
             data-gallery-image="side.jpg"
             src="{{ cms.media.galleryPath(product.id, 'side.jpg', {w: 100, h: 100}) }}">
        <img class="product-thumb"
             data-gallery-image="detail.jpg"
             src="{{ cms.media.galleryPath(product.id, 'detail.jpg', {w: 100, h: 100}) }}">
    </div>

    <button data-gallery="gallery-{{ product.id }}">View All Photos</button>
</div>
```

### Protected downloads
```twig
{% if cms.auth.verifyFilePassword(password, 'documents', docId, 'file') %}
    <a href="{{ cms.media.download(docId, {pwd: password}) }}">Download Document</a>
{% else %}
    <p>Invalid password</p>
{% endif %}
```

### Search with pagination
```twig
{% set results = cms.collection.search('blog', query, ['title', 'content', 'tags']) %}
{% set page = app.request.get('page', 1) %}
{% set perPage = 10 %}
{% set paged = results|paginate(perPage, page) %}

{% for item in paged %}
    <article>{{ item.title }}</article>
{% endfor %}

{{ cms.render.paginationFull(results|length, page, perPage) }}
```

### Text watermark examples
```twig
{# Simple text watermark #}
{{ cms.media.imagePath('hero-image', {
    w: 1200,
    h: 600,
    marktext: 'Copyright 2024'
}) }}

{# Styled text watermark with custom font #}
{{ cms.media.imagePath('product-photo', {
    w: 800,
    marktext: 'Premium Quality',
    marktextfont: 'Dorsa-Regular',
    marktextsize: 120,
    marktextcolor: 'ffffff',
    marktextbg: '000000',
    marktextpad: 20,
    marktextpos: 'bottom-right',
    marktextalpha: 80
}) }}

{# Rotated watermark #}
{{ cms.media.imagePath('landscape', {
    marktext: 'DRAFT',
    marktextsize: 200,
    marktextangle: -45,
    marktextcolor: 'ff0000',
    marktextpos: 'center',
    marktextalpha: 50
}) }}
```
