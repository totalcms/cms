---
title: "Twig Render Reference"
description: "Reference for the cms.render namespace providing HTML rendering for images, galleries, pagination, load more, depot browsers, and clone dialogs."
---

# cms.render

The render adapter generates complete HTML output for images, galleries, pagination, and other UI components. For URL-only access, see [cms.media](docs/twig/media).

## Images

Image functions take **three separate arguments**: the object, image transform options, and collection/render context. These are separate objects — do not merge them.

```twig
cms.render.image(object, {transforms}, {context})
                  │         │              │
                  │         │              └── collection, property, loading
                  │         └── w, h, fit, fm, etc.
                  └── object ID or full object
```

### image()

Render a complete `<img>` tag with ImageWorks URL, dimensions, alt text, and lazy loading.

```twig
{# Basic image #}
{{ cms.render.image('hero') }}

{# With ImageWorks transformations #}
{{ cms.render.image('hero', {w: 800, h: 600, fit: 'crop'}) }}

{# Custom collection and property — note: separate argument from transforms #}
{{ cms.render.image('widget', {}, {collection: 'products', property: 'photo'}) }}

{# Pass object directly (recommended) #}
{{ cms.render.image(product, {w: 600}, {collection: 'products', property: 'image'}) }}

{# Eager loading (default is lazy) #}
{{ cms.render.image('hero', {}, {loading: 'eager'}) }}

{# Your own alt text, or an empty one for a decorative image #}
{{ cms.render.image(post, {}, {alt: post.title, collection: 'blog'}) }}
{{ cms.render.image('divider', {}, {alt: ''}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array\|null | required | Object ID or full object data |
| `imageworks` | array | `[]` | ImageWorks transformation parameters (`w`, `h`, `fit`, `fm`, etc.) |
| `options` | array | `[]` | Collection context: `collection`, `property`, `loading`, `class`, `alt` and `protect`. `alt` is the alt text for this render; without it the image's own alt applies, then its EXIF title, description, and finally the filename. Pass an empty string for a decorative image; otherwise the filename fallback is what a screen reader hears. `protect` is covered under [Protecting images](#protecting-images) |

#### Nested images (cards and decks)

Images stored inside a `card` or `deck` field are addressed through the `property` option using a **dot-notation path**:

```twig
{# Card child — first segment is the card field, last segment is the child key #}
{{ cms.render.image('post-1', {w: 800}, {property: 'mycard.image'}) }}

{# Deck child — first segment is the deck field, then the deck-item id, then the child key #}
{{ cms.render.image('post-1', {w: 800}, {property: 'mydeck.item-3.image'}) }}
```

`cms.render.alt()` accepts the same `property: 'parent.child'` syntax. See [cms.media → Nested images](docs/twig/media#nested-images-cards-and-decks) for the underlying URL convention.

### picture()

Render a responsive `<picture>`: one `<source>` per modern format, each carrying a `srcset` of ImageWorks candidates, then an `<img>` fallback in the image's own format. The fallback carries the same `srcset`, so a browser that ignores `<picture>` still picks a sensible size.

Same three arguments as `image()`: the object, then **ImageWorks transforms**, then collection/render context. The transforms are applied to every candidate in every `srcset` — the only thing that varies between candidates is `w`, which `picture()` sets itself.

```twig
{# Defaults: 480/768/1024/1440/1920 candidates, AVIF + WebP sources, sizes="100vw" #}
{{ cms.render.picture('hero') }}

{# An image that never spans the viewport — tell the browser, or it downloads for 100vw #}
{{ cms.render.picture(post, {}, {sizes: '(min-width: 60em) 50vw, 100vw', collection: 'blog'}) }}

{# Your own candidate widths and a single format #}
{{ cms.render.picture(post, {}, {widths: [400, 800, 1200], formats: ['webp']}) }}

{# The transforms apply to every candidate; w there is a ceiling, not a candidate #}
{{ cms.render.picture(post, {w: 1200, h: 675, fit: 'crop-focalpoint', q: 75}) }}

{# A different image for dark mode: another object, or a second property on the same object #}
{{ cms.render.picture('logo', {}, {dark: 'logo-dark'}) }}
{{ cms.render.picture(post, {}, {darkProperty: 'imageDark', collection: 'blog'}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array\|null | required | Object ID or full object data |
| `imageworks` | array | `[]` | ImageWorks transforms applied to **every** candidate — `h`, `fit`, `q`, a preset `p`, and so on. Two keys behave differently from `image()`: `w` is a ceiling on the largest candidate rather than a candidate itself, and `fm` is ignored, because each `<source>` sets its own format from the `formats` option |
| `options` | array | `[]` | The picture options below, plus the same context as `image()`: `collection` (default `'image'`), `property` (default `'image'`, dotted for card/deck-nested), `loading` (default `'lazy'`) and `class` |

| Option | Type | Default | Description |
|---|---|---|---|
| `widths` | int[] | `[480, 768, 1024, 1440, 1920]` | Candidate widths for every `srcset`. Any wider than the source are dropped and the source width joins as the largest — ImageWorks never upscales, so a wider candidate would deliver the source's own width and mis-report it |
| `formats` | string[] | `['avif', 'webp']` | One `<source>` per format, in order, best first. A format equal to the image's own is skipped as redundant. Any of `jpg`, `png`, `webp`, `avif` |
| `sizes` | string | `'100vw'` | The `sizes` attribute on every `<source>` and the `<img>`. `100vw` is what a browser assumes without it, so narrow it per call |
| `dark` | string\|array | — | The image to show under `prefers-color-scheme: dark` — an object ID or object, resolved with the same `collection` and `property` as the light image |
| `darkProperty` | string | — | The dark image as a second image property on the **same** object (dotted for card/deck-nested). `dark` wins when both are given |
| `alt` | string | the image's own | Alt text for the `<img>`, as in `image()`. The dark image never contributes alt text |
| `protect` | bool | `imageworks.protect` | Drag and context-menu protection on the `<img>` — see [Protecting images](#protecting-images) |
| `collection`, `property`, `loading`, `class` | | as `image()` | Collection context, dotted nested `property`, lazy loading, extra class |

Every `w` descriptor is the width ImageWorks will actually deliver for that candidate, after `crop`, `h` and `fit` are applied — never the requested number. A `crop` rect (`crop: '520,717,0,100'`, as width, height, x, y) is cut before anything is sized, so the rect is the source: candidates wider than it are dropped, its width joins as the largest, and the `<img>` carries the rect's dimensions. Two requested widths that clamp to one delivered width collapse into one candidate. A GIF gets no `<source>` children at all, since re-encoding to a still format would drop its animation. Change the defaults site-wide under `imageworks.picture` in `config/tcms.php`; see [Format & Quality](docs/twig/imageworks#format--quality) for the format list.

#### Light and dark mode

With `dark` or `darkProperty` set, the `<picture>` leads with one `<source media="(prefers-color-scheme: dark)">` per format for the dark image, then the light sources and the light `<img>` as usual. A browser takes the first `<source>` whose media query and type both match, and re-evaluates the media query live, so the image swaps when the visitor's theme changes — no CSS or JavaScript involved. Nothing is needed for light mode: the unqualified sources and the `<img>` already are the light image.

The dark image gets its own candidate list, capped at its own width, with the same transforms, `widths`, `formats` and `sizes`. Because the dark set has no `<img>` of its own to fall back to, its stored format is appended after the requested formats as a last resort. Alt text, `width`/`height` and the link wrapper come from the light image. A dark image that is missing, empty or a GIF is ignored and the markup is exactly what it would be without the option.

### alt()

Get the alt text for an image. Falls back through alt text, EXIF data, then filename.

```twig
{{ cms.render.alt('hero') }}
{{ cms.render.alt(product, {collection: 'products', property: 'image'}) }}

{# Card child #}
{{ cms.render.alt('post-1', {property: 'mycard.image'}) }}
```

### Protecting images

Every `<img>` these helpers write — `image()`, `picture()`, `gallery()` thumbnails and `galleryImage()` — carries `draggable="false"` and a disabled context menu, which stops the casual drag-to-desktop and right-click-save. It is a courtesy, not security: the URL is in the markup and the browser has already downloaded the file.

Turn it off site-wide in `config/tcms.php`, or per call with the `protect` option, which wins either way:

```php
$settings['imageworks']['protect'] = false;
```

```twig
{# A logo visitors are welcome to grab #}
{{ cms.render.image('logo', {}, {protect: false}) }}

{# Protected even on a site that has turned it off #}
{{ cms.render.picture(photo, {}, {protect: true, collection: 'portfolio'}) }}
```

## Video

### cms.render.video()

Render a `video` field property (or a local `file`-field video upload) as an
embed, a `<video>` element, or a click-to-play facade — whichever fits the
provider.

```twig
{# An object from the ready-made video collection: collection and property both default to "video".
   Hosted providers render as a click-to-play facade (poster + play button) by default. #}
{{ cms.render.video('intro') }}

{# Hosted provider (YouTube, Vimeo, Livid, Bunny, Cloudflare, Loom, Wistia, Publitio, Jet-Stream) on your own schema #}
{{ cms.render.video(post, {property: 'promo'}) }}

{# An eager iframe instead of the facade #}
{{ cms.render.video(post, {property: 'promo', facade: false}) }}

{# Direct file URL (the `file` provider) — a muted looping background clip #}
{{ cms.render.video(post, {property: 'trailer', autoplay: true, muted: true, loop: true}) }}

{# Facade options carry into the embed built on click #}
{{ cms.render.video(post, {property: 'promo', muted: true}) }}

{# The uploaded poster resized through ImageWorks — the trailing argument, since video options lead in a video API #}
{{ cms.render.video(post, {property: 'promo'}, {w: 1200}) }}

{# A file-field value (mime starting video/) streams through the same call #}
{{ cms.render.video(post, {property: 'localClip'}) }}
```

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

An `unknown` provider URL is never modified — it renders as a generic iframe
with the author's URL exactly as pasted. See [Video](docs/fields/video) for
the full provider table and field settings.

## Galleries

### gallery()

Render a complete gallery grid with LightGallery lightbox support.

```twig
{# Default gallery (300x200 thumbnails) #}
{{ cms.render.gallery('vacation') }}

{# Custom thumbnail size #}
{{ cms.render.gallery('vacation', {w: 150, h: 150}) }}

{# Thumbnail and full-size settings #}
{{ cms.render.gallery('vacation', {w: 150}, {w: 1200}) }}

{# With options #}
{{ cms.render.gallery('vacation', {w: 200}, {}, {
    maxVisible: 8,
    viewAllText: 'Show all photos',
    loop: true,
    download: false,
    captions: true,
    gridCaptions: true,
    sort: 'name',
    class: 'my-gallery'
}) }}
```

**Gallery Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `collection` | string | `'gallery'` | Collection identifier |
| `property` | string | `'gallery'` | Property name |
| `maxVisible` | int | `0` | Max visible thumbnails (0 = all) |
| `viewAllText` | string | `'View All'` | Text for the "view all" button |
| `captions` | bool\|string | `false` | Lightbox captions: `true` for default, or a template string |
| `gridCaptions` | bool\|string | `false` | Grid captions below thumbnails |
| `sort` | string\|array | `''` | Sort property (prefix with `-` for descending) or array of sort rules |
| `class` | string | `''` | CSS class on the gallery container |
| `loop` | bool | `true` | Loop gallery in lightbox |
| `download` | bool | `true` | Show download button in lightbox |
| `counter` | bool | `true` | Show image counter |
| `plugins` | array | `['zoom','thumbnail','fullscreen']` | LightGallery plugins |
| `zoomFromOrigin` | bool | `true` | Animate the lightbox open and close from the clicked thumbnail. Set `false` when thumbnails are cropped to a different shape than the originals — see below |
| `protect` | bool | `imageworks.protect` | Drag and context-menu protection on every thumbnail — see [Protecting images](#protecting-images) |

Every option that is not one of Total CMS's own (`collection`, `property`, `captions`, `gridCaptions`, `sort`, `class`, `maxVisible`, `viewAllText`, `featuredOnly`) is passed straight through to [LightGallery's settings](https://www.lightgalleryjs.com/docs/settings/), so `loop`, `download`, `counter`, `zoomFromOrigin`, `speed`, `mode` and the rest all work as documented there.

**Cropped thumbnails and the zoom animation.** LightGallery's opening animation grows the thumbnail into the full image. It assumes both have the same proportions: when the grid is square crops of landscape or portrait photos (`{w: 300, h: 300, fit: 'crop'}`), the thumbnail is stretched to the full image's shape during the zoom and snaps back once the image loads ([lightGallery #1698](https://github.com/sachinchoolur/lightGallery/issues/1698)). Turn the thumbnail zoom off for those galleries and the full image fades in instead:

```twig
{{ cms.render.gallery('vacation', {w: 300, h: 300, fit: 'crop'}, {}, {zoomFromOrigin: false}) }}
```

For caption templates and sorting details, see [totalcms.md](docs/twig/totalcms).

### galleryLauncher()

Generate a hidden template element with gallery data for programmatic lightbox initialization. Use this when you want custom trigger elements instead of the default grid.

```twig
{{ cms.render.galleryLauncher('vacation') }}
<button data-gallery="gallery-vacation">View Photos</button>

{# With settings #}
{{ cms.render.galleryLauncher('vacation', {w: 300}, {w: 1920}, {
    captions: true,
    trigger: '.gallery-thumb',
    loop: true,
    download: false
}) }}
```

**Additional Launcher Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `trigger` | string | `''` | CSS selector for trigger elements |
| `galleryId` | string | auto | Custom gallery ID (default: `{collection}-{id}`) |
| `sort` | string | `''` | Sort images by field (e.g., `name`, `-name` for descending) |
| `include` | string | `''` | Include filter — only images matching ALL criteria (e.g., `tags:landscape,featured:true`) |
| `exclude` | string | `''` | Exclude filter — remove images matching ANY criteria (e.g., `tags:archived`) |
| `search` | string | `''` | Full-text search across all image fields |

```twig
{# Filter to only landscape-tagged images #}
{{ cms.render.galleryLauncher('vacation', {w: 300}, {w: 1920}, {
    include: 'tags:landscape'
}) }}

{# Exclude archived images, sorted by name #}
{{ cms.render.galleryLauncher('vacation', {w: 300}, {w: 1920}, {
    exclude: 'tags:archived',
    sort: 'name'
}) }}

{# Search for images matching "sunset" #}
{{ cms.render.galleryLauncher('vacation', {w: 300}, {w: 1920}, {
    search: 'sunset'
}) }}
```

Filtering uses the same syntax as [Index Filtering](docs/apis/index-filter), including [wildcard patterns](docs/apis/index-filter#wildcard-patterns) for flexible string matching.

For complete launcher usage with trigger methods and opening at specific images, see [totalcms.md](docs/twig/totalcms).

### galleryImage()

Render a single gallery image as an `<img>` tag with LightGallery integration attributes (`data-gallery`, `data-gallery-image`).

```twig
{# By filename #}
{{ cms.render.galleryImage('vacation', 'sunset.jpg', {w: 300, h: 200}) }}

{# Dynamic selectors #}
{{ cms.render.galleryImage('vacation', 'first', {w: 400}) }}
{{ cms.render.galleryImage('vacation', 'last') }}
{{ cms.render.galleryImage('vacation', 'random') }}
{{ cms.render.galleryImage('vacation', 'featured') }}
```

### galleryAlt()

Get the alt text for a specific gallery image.

```twig
{{ cms.render.galleryAlt('vacation', 'sunset.jpg') }}
{{ cms.render.galleryAlt('vacation', 'sunset.jpg', {collection: 'photos'}) }}
```

### galleryCaption()

Get the caption for a gallery image, with optional template rendering.

```twig
{# Default caption (alt text fallback chain) #}
{{ cms.render.galleryCaption('vacation', 'sunset.jpg') }}

{# With template #}
{{ cms.render.galleryCaption('vacation', 'sunset.jpg', {}, '<h4>{alt}</h4><p>{exif.camera}</p>') }}
```

## Pagination

### paginationSimple()

Render simple Previous/Next pagination links.

```twig
{{ cms.render.paginationSimple(totalItems, currentPage, itemsPerPage) }}

{# With custom labels and page key #}
{{ cms.render.paginationSimple(items|length, page, 10, 'page', 'Prev', 'Next') }}

{# Preserve query string parameters #}
{{ cms.render.paginationSimple(total, page, 10, 'p', 'Previous', 'Next', {sort: 'date', q: query}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `totalObjects` | int | required | Total number of items |
| `currentPage` | int | required | Current page number |
| `pageLimit` | int | required | Items per page |
| `pageKey` | string | `'p'` | Query parameter name for page number |
| `prevContent` | string | `'Previous'` | Previous button text/HTML |
| `nextContent` | string | `'Next'` | Next button text/HTML |
| `getData` | array | `[]` | Additional query parameters to preserve |

### paginationFull()

Render full pagination with numbered page links, plus Previous/Next.

```twig
{{ cms.render.paginationFull(totalItems, currentPage, itemsPerPage) }}

{{ cms.render.paginationFull(items|length, page, 10, 'p', '← Previous', 'Next →', {sort: 'date'}) }}
```

Parameters are identical to `paginationSimple()`.

## Load More

### loadMore()

Generate an HTMX-powered "load more" trigger for paginated collection loading.

```twig
{{ cms.render.loadMore('blog', {template: 'blog/card.twig', limit: 10}) }}

{# Render first page server-side + HTMX for the rest #}
{{ cms.render.loadMore('blog', {template: 'blog/card.twig', limit: 10, load: true}) }}

{# With empty state for filtered results #}
{{ cms.render.loadMore('blog', {
    template: 'blog/card.twig',
    include: 'published:true',
    empty: '<p>No posts found.</p>'
}) }}
```

See [Load More Documentation](docs/twig/load-more) for full options and examples.

### loadMoreDataView()

Generate an HTMX-powered "load more" trigger for paginated DataView loading.

```twig
{{ cms.render.loadMoreDataView('recent-posts', {template: 'blog/card.twig', limit: 10}) }}
```

### loadMoreButton()

Generate a standalone HTMX button that appends items into an external container. Unlike `loadMore()`, the button can be placed anywhere on the page.

```twig
<div id="blog-feed"></div>
{{ cms.render.loadMoreButton('blog', {
    target: '#blog-feed',
    template: 'blog/card.twig',
    limit: 10,
    load: true
}) }}
```

See [Load More Documentation](docs/twig/load-more) for full options and examples.

### loadMoreDataViewButton()

Generate a standalone HTMX button for paginated DataView loading into an external container.

```twig
<div id="feed"></div>
{{ cms.render.loadMoreDataViewButton('recent-posts', {
    target: '#feed',
    template: 'cards/item.twig',
    limit: 20
}) }}
```

## Fragment URLs

The HTML-fragment endpoints behind Load More, exposed for your own `hx-get`
and `hx-post` attributes. Each returns a raw URL; Twig's autoescape handles it
inside an attribute. See [HTMX Recipes](docs/twig/htmx) for complete examples.

### queryUrl()

```twig
{{ cms.render.queryUrl('blog', {template: 'blog/card', limit: 12, search: 'php'}) }}
```

Options: `template` (required), `limit`, `offset`, `sort`, `include`, `exclude`, `search`, `mode`.

### viewQueryUrl()

```twig
{{ cms.render.viewQueryUrl('recent-posts', {template: 'cards/item', limit: 10}) }}
```

### objectFragmentUrl()

```twig
{{ cms.render.objectFragmentUrl('products', product.id, {template: 'products/quick-view'}) }}
```

### saveUrl()

```twig
{{ cms.render.saveUrl('contact', {template: 'forms/thanks'}) }}          {# hx-post target #}
{{ cms.render.saveUrl('posts', {id: post.id, template: 'posts/card'}) }} {# hx-put / hx-patch target #}
```

### incrementUrl()

```twig
{{ cms.render.incrementUrl('posts', post.id, 'likes') }}
```

## Depot Browser

### depotBrowser()

Render an interactive file browser for depot properties. Displays files in a nested folder tree with filtering, previews, and download links.

```twig
{# Basic depot browser #}
{{ cms.render.depotBrowser('my-object') }}

{# With options #}
{{ cms.render.depotBrowser('my-object', {
    collection: 'documents',
    property: 'files',
    filter: true,
    preview: true,
    tags: true,
    comments: true,
    class: 'my-depot'
}) }}

{# Filter by tags #}
{{ cms.render.depotBrowser('my-object', {filterTags: ['important', 'public']}) }}

{# Flat file list (no folders) #}
{{ cms.render.depotBrowser('my-object', {folders: false}) }}
```

**Depot Browser Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `collection` | string | `'depot'` | Collection identifier |
| `property` | string | `'depot'` | Property name |
| `filter` | bool | `false` | Show search/filter input |
| `preview` | bool | `false` | Show preview buttons with modal |
| `comments` | bool | `false` | Display file comments |
| `download` | bool | `true` | Make files downloadable (vs stream) |
| `tags` | bool | `false` | Display file tags |
| `folders` | bool | `true` | Preserve folder structure |
| `humanize` | bool | `true` | Convert filenames to title case |
| `class` | string | `''` | Custom CSS class |
| `reverseSort` | bool | `false` | Reverse file sort order |
| `filterTags` | array | `[]` | Filter files by tags (OR logic, case-insensitive) |

## Admin Helpers

### cloneDialog()

Render a clone dialog for duplicating objects in a collection. Used in the admin interface.

```twig
{{ cms.render.cloneDialog('blog') }}
```

## Grid Helpers

The render adapter includes a `grid` sub-object with helper methods for content grids:

```twig
{{ cms.render.grid.date(item.date, 'M j, Y') }}     {# Format date with fallback #}
{{ cms.render.grid.tags(item.tags, '/blog/tag') }}  {# Render tag list with links #}
{{ cms.render.grid.excerpt(item.summary, 160) }}    {# Generate text excerpt #}
{{ cms.render.grid.price(item.price) }}             {# Format price #}
{{ cms.render.grid.meta(item.author) }}             {# Render metadata (author, date) #}
```

See [cmsgrid tag](docs/twig/cmsgrid-tag) for the `{% cmsgrid %}` template tag documentation.
