---
title: "Site Builder"
description: "Build complete websites within Total CMS using Twig templates, page routes, and automatic URL routing — no external tools required."
since: "3.3.0"
---

# Site Builder

The Site Builder lets you build a complete frontend website within Total CMS. Pages are defined as collection objects with URL routes and templates, and a middleware-based router handles all URL matching and rendering automatically.

## How It Works

1. **Page objects** live in the `builder-pages` collection — each defines a URL route, a template, and metadata (title, description, layout)
2. **Templates** live in `tcms-data/builder/` — layouts, page templates, partials, and macros
3. **A routing middleware** matches incoming URLs against page routes and collection URL patterns
4. **Page data is passed to templates automatically** — available as `page` and `params` in Twig

No separate router files or PHP stubs are generated. The middleware runs inside T3's Slim pipeline and handles routing dynamically.

## Page Routes

Each page object defines a `route` — the URL pattern it responds to:

| Route | Type | Example URL |
|-------|------|-------------|
| `/` | Static | Homepage |
| `/about` | Static | About page |
| `/products` | Static | Products listing |
| `/products/{id}` | Dynamic | Individual product page |
| `/blog/{category}/{slug}` | Dynamic | Blog post with category |

Static routes match exactly. Dynamic routes use `{param}` placeholders that capture URL segments and pass them to the template as `params.param`.

## How Routing Works

The `PageRouterMiddleware` wraps the entire Slim pipeline. When a request comes in:

1. Slim tries to match the URL against API and admin routes first
2. If Slim returns a 404 (no API/admin route matched), the middleware intercepts
3. It checks builder page routes — static matches first, then dynamic patterns
4. If a page matches, it renders the template with page data and returns the response
5. If no page matches, it checks collection URL patterns
6. If nothing matches, the original 404 is returned

This means API routes (under `/api/`) and admin routes (under `/admin/`) always take priority. Builder pages only handle URLs that T3's API doesn't claim. You can safely create a builder page at `/collections` without conflicting with the API at `/api/collections`.

## Templates

Templates are organized into categories inside `tcms-data/builder/`:

### Layouts (`layouts/`)

Base HTML structure. Page templates extend layouts via the `layout` field on the page object.

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <title>{% block title %}{{ page.title }}{% endblock %}</title>
    <meta name="description" content="{{ page.description }}">
</head>
<body>
    {% include 'partials/nav.twig' %}
    <main>{% block content %}{% endblock %}</main>
    {% include 'partials/footer.twig' %}
</body>
</html>
```

### Pages (`pages/`)

Page content templates. Each extends a layout and renders page-specific content. Multiple page objects can share the same template.

```twig
{% extends 'layouts/' ~ page.layout ~ '.twig' %}

{% block content %}
<h1>{{ page.title }}</h1>
<p>Welcome to our site.</p>
{% endblock %}
```

### Partials (`partials/`)

Reusable fragments — navigation, footer, cards. Included via `{% include %}`.

### Macros (`macros/`)

Reusable Twig functions for repeated rendering patterns.

## Template Data

When a page route matches, the template receives two variables:

### `page`

The full page object from the collection:

```twig
{{ page.title }}        {# Page Title #}
{{ page.description }}  {# Meta description #}
{{ page.layout }}       {# Layout name #}
{{ page.route }}        {# URL pattern #}
{{ page.template }}     {# Template name #}
{{ page.sort }}         {# Sort order #}
{{ page.parent }}       {# Parent page ID #}
```

### `params`

Extracted URL parameters from dynamic routes:

```twig
{# Route: /products/{id} — URL: /products/widget-x #}
{{ params.id }}  {# "widget-x" #}

{# Route: /blog/{category}/{slug} — URL: /blog/tech/my-post #}
{{ params.category }}  {# "tech" #}
{{ params.slug }}      {# "my-post" #}
```

Use params to fetch collection data:

```twig
{% set product = cms.data.raw('products', params.id) %}
<h1>{{ product.title }}</h1>
```

## Collection URL Routing

The middleware also matches collection URL patterns. If a collection has a `url` field set (e.g., `/blog` with pretty URLs enabled), visiting `/blog/my-post` automatically:

1. Matches the URL to the blog collection
2. Fetches the `my-post` object
3. Renders the collection's template (`templates/{collection-id}.twig`)
4. Passes the object data as `page` and the collection ID

This works with both simple URLs (`/blog/{id}`) and template URLs (`/blog/{{ category }}/{{ id }}`).

## Linking to Collection Objects

A common pattern is a **list page** that shows collection objects in a grid, linking to a **detail page** that shows a single object. Here's how to set this up with builder pages.

### Setup

Create two builder pages and configure the collection's URL:

| Page | Route | Template | Purpose |
|------|-------|----------|---------|
| Blog | `/blog` | `blog-index` | Lists all posts |
| Blog Post | `/blog/{id}` | `blog-post` | Shows a single post |

Set the blog collection's URL to `/blog` with **Pretty URLs** enabled. This makes `objectUrl()` generate URLs like `/blog/my-post` that match the builder page route.

The Blog Post page should have **Show in Nav** set to `false` — it's a dynamic page, not a navigation item.

### List Page Template

Use `objectUrl()` to generate links to the detail page:

```twig
{% set posts = cms.collection.objects('blog') | sortCollectionByString('date:desc') %}
{% for post in posts %}
    <article>
        <h2><a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a></h2>
        <p>{{ post.summary }}</p>
    </article>
{% endfor %}
```

`objectUrl()` reads the collection's URL settings and generates the correct path. Pass the full object (not just the ID) for best performance with templated URLs.

### Detail Page Template

Use `params` to load the object from the URL:

```twig
{% set post = cms.data.raw('blog', params.id) %}

{% if post %}
    <h1>{{ post.title }}</h1>
    <div>{{ post.content | raw }}</div>
{% else %}
    <h1>Post Not Found</h1>
    <p>The post <code>{{ params.id }}</code> could not be found.</p>
{% endif %}
```

The `params` variable contains extracted URL parameters. For route `/blog/{id}`, visiting `/blog/my-post` sets `params.id` to `my-post`.

### How It Works

1. `objectUrl('blog', post)` generates `/blog/my-post` (from collection URL settings)
2. User clicks the link
3. PageRouterMiddleware intercepts the request
4. Builder page `blog-post` with route `/blog/{id}` matches
5. Template receives `params.id = 'my-post'` and loads the object

### With Templated URLs

For more complex URL patterns, use URL templates on the collection:

```
Collection URL: /blog/{{ category }}/{{ id }}
Builder page route: /blog/{category}/{id}
```

The detail template receives both parameters:

```twig
{% set post = cms.data.raw('blog', params.id) %}
{# params.category is also available #}
```

See [Object Linking](docs/twig/object-linking) for full documentation on URL templates, canonical URLs, and redirects.

## Pages Collection

Page metadata is stored in the `builder-pages` collection using the `builder-page` schema.

### Page Schema Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | slug | Page identifier (auto-generated from title) |
| `title` | text | Page title |
| `route` | text | URL pattern (e.g., `/about` or `/products/{id}`) |
| `template` | text | Page template name from `builder/pages/` |
| `layout` | select | Layout template from `builder/layouts/` |
| `description` | textarea | Meta description |
| `draft` | toggle | Exclude from routing |
| `nav` | toggle | Include in navigation menus (default: true) |
| `sort` | number | Navigation ordering (lower = first) |
| `parent` | select | Hierarchical parent page |

## Navigation

T3 provides Twig functions to build navigation menus from your pages collection. These functions automatically filter out draft pages and pages with `nav` set to false, and sort by the `sort` field.

### Top-Level Navigation

```twig
{% for p in cms.builder.nav() %}
    <a href="{{ p.route }}">{{ p.title }}</a>
{% endfor %}
```

### Sub-Navigation

Get the children of a specific page:

```twig
{% for p in cms.builder.subnav('blog') %}
    <a href="{{ p.route }}">{{ p.title }}</a>
{% endfor %}
```

### Multi-Level Navigation Tree

Get the full page hierarchy with nested `children` arrays:

```twig
{% set tree = cms.builder.navTree() %}
{% for p in tree %}
    <a href="{{ p.route }}">{{ p.title }}</a>
    {% if p.children is not empty %}
    <ul>
        {% for child in p.children %}
            <li><a href="{{ child.route }}">{{ child.title }}</a></li>
        {% endfor %}
    </ul>
    {% endif %}
{% endfor %}
```

### Custom Collection

All three functions accept an optional collection ID to use a different pages collection:

```twig
{% set pages = cms.builder.nav('my-custom-pages') %}
{% set children = cms.builder.subnav('services', 'my-custom-pages') %}
{% set tree = cms.builder.navTree('my-custom-pages') %}
```

### Draft vs Nav

The `draft` and `nav` toggles serve different purposes:

- **Draft** controls whether the page is routable — a draft page cannot be visited at all
- **Nav** controls whether the page appears in navigation — a page can be published (not draft) but hidden from menus (nav: false)

This is useful for pages like privacy policies, 404 pages, or dynamic sub-pages (e.g., `/blog/{id}`) that should be accessible but not listed in navigation.

## URL Structure

T3 uses the following URL structure:

| Path | Purpose |
|------|---------|
| `/api/*` | REST API endpoints (collections, schemas, templates, etc.) |
| `/admin/*` | Admin dashboard and auth pages (login, logout, etc.) |
| `/setup/*` | Setup wizard |
| `/*` | Builder pages and collection URLs |

API routes always take priority over builder pages.

## Serving Non-HTML Files (robots.txt, llms.txt, etc.)

The Site Builder isn't just for HTML pages. Any text-based file the web expects at a specific path — `robots.txt`, `llms.txt`, `ads.txt`, `security.txt`, `humans.txt`, `manifest.json`, custom RSS feeds — can be served as a builder page. Total CMS auto-detects the right `Content-Type` from the route's file extension.

### How It Works

1. Create a builder page with a route like `/robots.txt`
2. Pick (or write) a template that renders the file's contents
3. Visit `/robots.txt` — served with the correct `Content-Type` header

The middleware inspects the route's extension and maps it to the appropriate MIME type:

| Extension | Content-Type |
|-----------|--------------|
| `.txt` | `text/plain` |
| `.xml` | `application/xml` |
| `.rss` | `application/rss+xml` |
| `.json` | `application/json` |
| `.md` | `text/markdown` |
| `.css` | `text/css` |
| `.js` | `application/javascript` |
| `.csv` | `text/csv` |
| `.svg` | `image/svg+xml` |
| _(none / unknown)_ | `text/html` |

### Example: robots.txt

Create a builder page with route `/robots.txt` and a template like:

```twig
User-agent: *
Disallow: /admin/
Disallow: /api/

Sitemap: https://{{ cms.config('domain') }}/sitemap.xml
```

Visit `/robots.txt` and you get plain-text output with `Content-Type: text/plain; charset=utf-8`.

### Example: llms.txt

The [llms.txt](https://llmstxt.org/) standard tells AI crawlers how to navigate your site. Create a builder page with route `/llms.txt` and a template like:

```twig
# {{ cms.config('domain') }}

> {{ cms.config('description') }}

## Pages

{% for p in cms.builder.nav() %}
- [{{ p.title }}]({{ p.route }}): {{ p.description }}
{% endfor %}
```

### Twig Is Available

Templates for non-HTML files have full Twig support — use `cms.config()`, `cms.builder.nav()`, collection lookups, etc. to make these files dynamic. The CMS doesn't post-process the output; whatever your template emits is what's served. **Make sure your template's output matches the file format** (no stray whitespace in `robots.txt` directives, valid JSON for `.json` routes, etc.).

If your template content includes literal `{{` or `{%` characters that you don't want Twig to evaluate, wrap them in `{% verbatim %}{% endverbatim %}` blocks.

## Coexistence with Stacks

For Stacks sites where T3 is installed at a subpath, Stacks-published pages are static PHP files that serve directly. Configure your `.htaccess` to route unmatched requests to T3:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ /path/to/tcms [QSA,L]
```

## See Also

- [Frontend Assets](docs/builder/frontend) — Vite setup, CSS/JS compilation, and build tool configuration
- [Builder Twig Reference](docs/twig/builder) — `nav()`, `subnav()`, `navTree()`, `css()`, `js()`, `asset()`, `preload()`
- [Builder CLI Commands](docs/builder/cli)
- [Builder Admin UI](docs/builder/admin)
- [Starter Templates](docs/builder/starters)
