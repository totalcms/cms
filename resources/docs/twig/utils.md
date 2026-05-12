---
title: "Utilities"
description: "Utility functions for Total CMS including URL-based filtering that converts query parameters into include, exclude, sort, and search options."
---

# cms.utils

The `cms.utils` namespace provides helper functions for common tasks in Total CMS templates.

## URL Helpers

### urlFilters()

Reads URL query parameters and converts them into `include`, `exclude`, `sort`, and `search` options compatible with `loadMore`, `loadMoreButton`, `loadMoreDataView`, and any other function that accepts these filter options.

This allows visitors to filter, sort, and search collection content using clean, shareable URLs without any JavaScript.

#### Basic Usage

```twig
{% set filters = cms.utils.urlFilters() %}
{{ cms.render.loadMore('blog', {
    template: 'blog/card',
    include: filters.include,
    exclude: filters.exclude,
    sort: filters.sort,
    search: filters.search
}) }}
```

#### URL Format

Property filters use the parameter name as the field name and the value as the filter value.

| URL Parameter | Result | Description |
|---|---|---|
| `?category=travel` | include: `category:travel` | Include objects where category is "travel" |
| `?tag=-beach` | exclude: `tag:beach` | Exclude objects where tag is "beach" (- prefix) |
| `?category=travel&status=published` | include: `category:travel,status:published` | Multiple includes (AND logic) |
| `?sort=-date` | sort: `-date` | Sort by date descending |
| `?search=adventure` | search: `adventure` | Full-text search |

The `-` prefix on a value means **exclude** instead of include:

```
?published=true&draft=-true
→ include: "published:true"
→ exclude: "draft:true"
```

#### Complete Example

Given this URL:

```
https://example.com/blog?category=travel&tag=-beach&sort=-date&search=adventure
```

`cms.utils.urlFilters()` returns:

```json
{
    "include": "category:travel",
    "exclude": "tag:beach",
    "sort": "-date",
    "search": "adventure"
}
```

#### Array Values

Use bracket syntax for multiple values on the same field:

```
?tags[]=travel&tags[]=vacation
→ include: "tags:travel,tags:vacation"
```

You can mix include and exclude within the same field:

```
?tags[]=travel&tags[]=-beach
→ include: "tags:travel"
→ exclude: "tags:beach"
```

#### Custom Parameter Names

By default, `sort` and `search` are the reserved parameter names. You can customize these to match your URL scheme or localize them:

```twig
{% set filters = cms.utils.urlFilters({
    sort: 'orderby',
    search: 'q'
}) %}
```

Now `?orderby=-date&q=hello` maps to sort and search instead of `?sort=-date&search=hello`.

When you rename a reserved parameter, the default name becomes available as a property filter:

```
?sort=asc&orderby=-date
→ With {sort: 'orderby'}: sort="-date", include="sort:asc"
```

#### Ignoring Parameters

Some URL parameters aren't filters — like pagination or tracking IDs. Use `ignore` to skip them:

```twig
{% set filters = cms.utils.urlFilters({
    ignore: 'page,id,ref'
}) %}
```

Now `?page=2&id=abc&category=travel` only produces `include: "category:travel"`.

#### Options Reference

| Option | Default | Description |
|---|---|---|
| `sort` | `"sort"` | URL parameter name for sort |
| `search` | `"search"` | URL parameter name for search |
| `ignore` | `""` | Comma-separated parameter names to skip |

#### Combining with Hardcoded Filters

You can merge URL-based filters with hardcoded defaults using Twig's `~` operator for strings:

```twig
{% set filters = cms.utils.urlFilters() %}
{% set baseInclude = 'published:true' %}
{% set include = filters.include ? baseInclude ~ ',' ~ filters.include : baseInclude %}

{{ cms.render.loadMore('blog', {
    template: 'blog/card',
    include: include,
    exclude: filters.exclude,
    sort: filters.sort | default('-date'),
    search: filters.search
}) }}
```

This ensures `published:true` is always applied, while visitors can add additional filters via URL.

#### Building Filter Links

Create navigation links that set URL parameters for filtering:

```html
<nav>
    <a href="?category=travel">Travel</a>
    <a href="?category=food">Food</a>
    <a href="?category=tech">Tech</a>
    <a href="?sort=-date">Newest First</a>
    <a href="?sort=title">A-Z</a>
</nav>
```

Or combine filters:

```html
<a href="?category=travel&sort=-date">Latest Travel Posts</a>
```

#### Search Form

Build a simple search form that sets the URL parameter:

```html
<form method="get" action="">
    <input type="search" name="search" value="{{ getData.search }}" placeholder="Search...">
    <button type="submit">Search</button>
</form>
```

#### Use with Other Functions

`urlFilters` works anywhere include/exclude/sort are accepted, not just with loadMore:

```twig
{% set filters = cms.utils.urlFilters() %}

{# With galleries #}
{{ cms.render.gallery('photos', 'main', {
    include: filters.include,
    exclude: filters.exclude,
    sort: filters.sort
}) }}

{# With RSS feeds #}
{{ cms.render.rssFeed('blog', {
    include: filters.include,
    exclude: filters.exclude
}) }}

{# With sitemaps #}
{{ cms.render.sitemap('blog', {
    include: filters.include,
    exclude: filters.exclude
}) }}
```
