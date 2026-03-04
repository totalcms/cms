---
title: "Load More"
description: "Add HTMX-powered infinite scroll and load more buttons to Total CMS collection pages with server-side first page rendering and API pagination."
---

# Load More

Total CMS provides "load more" helpers that render the first page of results server-side, then fetch subsequent pages via API as the user scrolls or clicks a button.

## Requirements

- **Edition**: Standard edition or higher (requires templates feature)
- **Template file**: A Twig template that renders a single item (receives an `{{ object }}` variable)
- **HTMX**: Include the HTMX script in your page or load from a CDN

```html
<script src="{{ cms.api }}/assets/htmx.min.js?v={{ cms.version }}"></script>
```

## Collection Load More

Use `cms.render.loadMore()` to paginate objects from a collection:

```twig
{{ cms.render.loadMore('blog', {
    template: 'blog/card.twig',
    limit: 10
}) }}
```

This renders the first 10 blog objects using `blog/card.twig`, then appends an HTMX trigger that automatically fetches the next page when needed.

## DataView Load More

Use `cms.render.loadMoreDataView()` to paginate results from a saved DataView:

```twig
{{ cms.render.loadMoreDataView('recent-posts', {
    template: 'blog/card.twig',
    limit: 10
}) }}
```

Works identically to the collection version but queries a DataView by its ID.

## Options Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `template` | string | **required** | Twig template file for rendering each item. Receives `{{ object }}` |
| `limit` | int | `20` | Number of items per page |
| `sort` | string | — | Sort field (e.g., `date`, `-date` for descending) |
| `include` | string | — | Include filter (e.g., `published:true,featured:true`) |
| `exclude` | string | — | Exclude filter (e.g., `draft:true`) |
| `search` | string | — | Search query string |
| `trigger` | string | `'revealed'` | HTMX trigger mode: `revealed` or `click` |
| `buttonLabel` | string | `'Load More'` | Button label (only used when trigger is `click`) |
| `buttonClass` | string | — | Additional CSS class for the trigger element |
| `transition` | bool | `false` | Enable HTMX view transitions |
| `load` | bool | `false` | Render the first page of items server-side (SEO-friendly) |
| `empty` | string | — | HTML to display when filters match zero items |

## Trigger Modes

### Infinite Scroll (`revealed`)

The default mode. A hidden `<div>` is placed after the rendered items. When it scrolls into view, HTMX automatically fetches the next page.

```twig
{{ cms.render.loadMore('blog', {
    template: 'blog/card.twig',
    trigger: 'revealed'
}) }}
```

### Load More Button (`click`)

Renders a `<button>` that the user clicks to load additional items.

```twig
{{ cms.render.loadMore('blog', {
    template: 'blog/card.twig',
    trigger: 'click',
    buttonLabel: 'Show More Posts'
}) }}
```

## Empty State

When using filters like `include`, `exclude`, or `search`, it's possible that zero items match. By default, loadMore renders a hidden HTMX trigger that fetches nothing — the user sees blank space. The `empty` option lets you display a message instead:

```twig
{{ cms.render.loadMore('blog', {
    template: 'blog/card.twig',
    include: 'published:true',
    empty: '<p>No published posts found.</p>'
}) }}
```

When `empty` is set, Total CMS runs a lightweight count query with the same filters. If zero items match, the empty HTML is rendered instead of the HTMX trigger. If items exist, the normal load more behavior kicks in.

The empty content is wrapped in a `<div class="cms-no-results">` that you can style:

```css
.cms-no-results {
    text-align: center;
    padding: 2rem;
    color: #666;
}
```

The `empty` value supports any HTML, so you can include links, images, or other markup.

## Server-Side Loading (`load`)

By default, `loadMore()` only outputs the HTMX trigger — you render the first page yourself with a `{% for %}` loop. The `load` option tells `loadMore()` to handle everything: render the initial items server-side (important for SEO) and append the HTMX trigger for subsequent pages.

```twig
{# One line does it all — first page rendered server-side, rest via HTMX #}
<div class="blog-feed">
    {{ cms.render.loadMore('blog', {
        template: 'blog/card.twig',
        limit: 12,
        sort: '-date',
        include: 'published:true',
        load: true
    }) }}
</div>
```

Without `load`, you must render the first page manually:

```twig
{# Without load — manual first page + HTMX for the rest #}
<div class="blog-feed">
    {% for object in cms.collection.query('blog', {limit: 12, sort: '-date', include: 'published:true'}).items %}
        {% include 'blog/card.twig' %}
    {% endfor %}
    {{ cms.render.loadMore('blog', {
        template: 'blog/card.twig',
        limit: 12,
        sort: '-date',
        include: 'published:true'
    }) }}
</div>
```

Both approaches produce identical output. The `load` option simply reduces boilerplate.

## How It Works

1. **Initial render**: The first page of items is rendered server-side into the page HTML (automatically when using `load: true`, or manually via a `{% for %}` loop)
2. **HTMX trigger**: After the last item, a trigger element is injected (a `<div>` for infinite scroll or a `<button>` for click)
3. **API request**: When triggered, HTMX sends a GET request to the query endpoint with `offset` and `limit` parameters
4. **Response**: The server returns the next batch of rendered HTML plus a new trigger element for the following page
5. **Swap**: HTMX swaps the trigger element with the new content (items + next trigger) using `outerHTML`
6. **Chain continues**: This repeats until no more items remain, at which point no trigger is returned

## Template Files

Create a Twig template that renders a single item. The template receives the current object as `{{ object }}`:

```twig
{# templates/blog/card.twig #}
<article class="blog-card">
    <h2><a href="{{ cms.collection.objectUrl('blog', object.id) }}">{{ object.title }}</a></h2>
    <time>{{ object.date }}</time>
    <p>{{ object.excerpt }}</p>
</article>
```

The same template is used for both the initial server render and all subsequent HTMX-loaded pages.

## Styling

The trigger element uses the `cms-load-more` CSS class, which you can target for custom styling:

```css
.cms-load-more {
    text-align: center;
    padding: 2rem 0;
}

/* Style the load more button */
button.cms-load-more {
    background: #333;
    color: #fff;
    padding: 0.75rem 2rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
```

## Examples

### Blog with Infinite Scroll

```twig
<div class="blog-feed">
    {{ cms.render.loadMore('blog', {
        template: 'blog/card.twig',
        limit: 12,
        sort: '-date',
        include: 'published:true',
        exclude: 'draft:true'
    }) }}
</div>
```

### Product Grid with Button

```twig
<div class="product-grid">
    {{ cms.render.loadMore('products', {
        template: 'products/tile.twig',
        limit: 24,
        trigger: 'click',
        buttonLabel: 'Load More Products',
        include: 'instock:true'
    }) }}
</div>
```

### DataView Dashboard

```twig
<div class="recent-activity">
    <h2>Recent Activity</h2>
    {{ cms.render.loadMoreDataView('recent-activity', {
        template: 'dashboard/activity-row.twig',
        limit: 20,
        trigger: 'revealed'
    }) }}
</div>
```

### Filtered Collection with Empty State

```twig
<div class="blog-feed">
    {{ cms.render.loadMore('blog', {
        template: 'blog/card.twig',
        limit: 12,
        include: 'category:news',
        empty: '<p>No news articles have been published yet.</p>'
    }) }}
</div>
```

## External Button

The standard `loadMore()` uses a self-replacing sentinel pattern — the trigger element lives inside the content container. If you want a "Load More" button placed anywhere on the page (sidebar, fixed header, etc.) separate from where items appear, use `loadMoreButton()`.

### How It Works

1. `loadMoreButton()` outputs a `<button>` that targets a container via CSS selector
2. User clicks → HTMX fetches items and appends them into the target container
3. Server responds with rendered items plus an out-of-band swap that updates the button's URL with the next offset
4. When no more items exist, the OOB swap removes the button from the DOM

### Collection External Button

```twig
<div id="blog-feed"></div>
{{ cms.render.loadMoreButton('blog', {
    target: '#blog-feed',
    template: 'blog/card.twig',
    limit: 10
}) }}
```

### DataView External Button

```twig
<div id="activity-feed"></div>
{{ cms.render.loadMoreDataViewButton('recent-posts', {
    target: '#activity-feed',
    template: 'cards/item.twig',
    limit: 20
}) }}
```

### Auto-Load First Batch

Use `load: true` to auto-fetch the first batch on page load (the button also responds to clicks for subsequent pages):

```twig
<div id="blog-feed"></div>
{{ cms.render.loadMoreButton('blog', {
    target: '#blog-feed',
    template: 'blog/card.twig',
    limit: 10,
    load: true
}) }}
```

### Custom Offset

If you pre-rendered items server-side, set `offset` to skip those:

```twig
{# 5 items already rendered above #}
{{ cms.render.loadMoreButton('blog', {
    target: '#blog-feed',
    template: 'blog/card.twig',
    limit: 10,
    offset: 5
}) }}
```

### Full Options

```twig
{{ cms.render.loadMoreButton('blog', {
    target: '#blog-feed',
    template: 'blog/card.twig',
    limit: 10,
    offset: 0,
    sort: '-date',
    include: 'published:true',
    buttonLabel: 'Show More Posts',
    buttonClass: 'btn-primary',
    transition: true,
    id: 'my-load-btn'
}) }}
```

### External Button Options Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `target` | string | **required** | CSS selector for the container to append items into |
| `template` | string | **required** | Twig template for rendering each item |
| `limit` | int | `20` | Items per page |
| `offset` | int | `0` | Starting offset |
| `load` | bool | `false` | Auto-fetch first batch on page load |
| `sort` | string | — | Sort field |
| `include` | string | — | Include filter |
| `exclude` | string | — | Exclude filter |
| `search` | string | — | Search query |
| `buttonLabel` | string | `'Load More'` | Button text |
| `buttonClass` | string | — | Additional CSS classes on the button |
| `transition` | bool | `false` | Enable HTMX view transitions |
| `id` | string | auto-generated | Custom button ID |

### Sentinel vs External Button

| | Sentinel (`loadMore`) | External Button (`loadMoreButton`) |
|---|---|---|
| Trigger placement | Inside content container | Anywhere on the page |
| HTMX swap | `outerHTML` (self-replacing) | `beforeend` (append) + OOB button update |
| First page | `load: true` or manual `{% for %}` | `load: true` auto-fetches on page load |
| Pagination | Trigger chains automatically | Button URL updated via OOB swap |
| End of data | No trigger returned | Button removed from DOM |

## See Also

- [Index Filtering](docs/api/index-filter) — Include/exclude filter syntax
- [Total CMS Twig Adapter](docs/twig/totalcms) — Full `cms` variable reference
- [Pagination](docs/twig/totalcms#pagination) — Traditional page-based pagination
