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
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `idOrObject` | string\|array\|null | required | Object ID or full object data |
| `imageworks` | array | `[]` | ImageWorks transformation parameters (`w`, `h`, `fit`, `fm`, etc.) |
| `options` | array | `[]` | Collection context: `collection`, `property`, `loading` |

#### Nested images (cards and decks)

Images stored inside a `card` or `deck` field are addressed through the `property` option using a **dot-notation path**:

```twig
{# Card child — first segment is the card field, last segment is the child key #}
{{ cms.render.image('post-1', {w: 800}, {property: 'mycard.image'}) }}

{# Deck child — first segment is the deck field, then the deck-item id, then the child key #}
{{ cms.render.image('post-1', {w: 800}, {property: 'mydeck.item-3.image'}) }}
```

`cms.render.alt()` accepts the same `property: 'parent.child'` syntax. See [cms.media → Nested images](docs/twig/media#nested-images-cards-and-decks) for the underlying URL convention.

### alt()

Get the alt text for an image. Falls back through alt text, EXIF data, then filename.

```twig
{{ cms.render.alt('hero') }}
{{ cms.render.alt(product, {collection: 'products', property: 'image'}) }}

{# Card child #}
{{ cms.render.alt('post-1', {property: 'mycard.image'}) }}
```

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

Filtering uses the same syntax as [Index Filtering](docs/apis/index-filter), including [wildcard patterns](#wildcard-patterns) for flexible string matching.

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
{{ cms.render.grid.date(item, 'M j, Y') }}        {# Format date with fallback #}
{{ cms.render.grid.tags(item, '/blog/tag') }}       {# Render tag list with links #}
{{ cms.render.grid.excerpt(item, 160) }}            {# Generate text excerpt #}
{{ cms.render.grid.price(item) }}                   {# Format price #}
{{ cms.render.grid.meta(item) }}                    {# Render metadata (author, date) #}
```

See [cmsgrid tag](docs/twig/cmsgrid-tag) for the `{% cmsgrid %}` template tag documentation.
