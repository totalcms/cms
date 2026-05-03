---
title: "Site Builder"
description: "Build complete websites within Total CMS using Twig templates, page routes, and automatic URL routing — no external tools required."
since: "3.3.0"
---

# Site Builder

The Site Builder lets you build a complete frontend website within Total CMS. Pages are defined as collection objects with URL routes and templates, and a middleware-based router handles all URL matching and rendering automatically.

## How It Works

1. **Page objects** live in the `builder-pages` collection — each defines a URL route, a template, and metadata (title, description, image, status, etc.)
2. **Templates** live in `tcms-data/builder/` — layouts, page templates, partials, and macros
3. **A routing middleware** matches incoming URLs against page routes and collection URL patterns
4. **Page data is passed to templates automatically** — available as `page` and `params` in Twig (or `object` and `params` for collection-URL matches)

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
| `/docs/{path:.*}` | Catch-all | Multi-segment placeholder (matches across slashes) |

Static routes match exactly. Dynamic routes use `{param}` placeholders that capture URL segments and pass them to the template as `params.param`. A catch-all `{name:.*}` matches any number of segments — useful for documentation sites, file viewers, etc.

## How Routing Works

The `PageRouterMiddleware` wraps the entire Slim pipeline. When a request comes in:

1. Slim tries to match the URL against API and admin routes first
2. If Slim returns a 404 (no API/admin route matched), the middleware intercepts
3. It checks builder page routes — static matches first, then dynamic patterns (catch-alls last)
4. If no builder page matches, it tries collection URL patterns
5. If nothing matches at all, the middleware looks for a page with `status=404` and renders that as a custom 404 (see [Custom 404 Page](#custom-404-page))
6. If no 404 page is configured, the original Slim 404 is returned

API routes (under `/api/`) and admin routes (under `/admin/`) always take priority. Builder pages only handle URLs that T3's API doesn't claim. You can safely create a builder page at `/collections` without conflicting with the API at `/api/collections`.

## Templates

Templates are organized into categories inside `tcms-data/builder/`:

### Layouts (`layouts/`)

Base HTML structure. Page templates extend layouts via Twig's `{% extends %}` tag. There's no `layout` field on the page — extend whichever layout the template needs directly.

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
{% extends 'layouts/default.twig' %}

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

When a builder page route matches, the template receives:

### `page`

The full page object from the collection:

```twig
{{ page.title }}        {# Page Title #}
{{ page.description }}  {# Meta description #}
{{ page.route }}        {# URL pattern #}
{{ page.template }}     {# Template name #}
{{ page.image }}        {# Page image (used for og:image / hero) #}
{{ page.status }}       {# HTTP status code #}
{{ page.data.hero }}    {# Custom JSON data — see Page Data #}
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

### Collection URL Matches Use `object`

When the request matches a **collection URL** (not a builder page), the template receives the matched record as `object` instead of `page`. The collection's template lives at `pages/{collection-id}.twig` and can read fields off the collection object directly:

```twig
{# pages/blog.twig — rendered when a /blog/* URL matches the blog collection #}
<article>
    <h1>{{ object.title }}</h1>
    <p>{{ object.summary }}</p>
    <div>{{ object.content | raw }}</div>
</article>
```

This convention keeps `page.*` reserved for builder-page records and `object.*` for collection-sourced records — it's clear at a glance which kind of route the template is serving.

## Page Data

The `data` field is a free-form JSON blob attached to a page, exposed to the template as `page.data.*`. Use it for one-off page content that doesn't justify creating a one-row collection — hero text, CTAs, feature lists, etc.

```json
{
    "hero": "Welcome to Acme",
    "cta": "Sign up free",
    "features": ["Fast", "Reliable", "Secure"]
}
```

```twig
<section class="hero">
    <h1>{{ page.data.hero }}</h1>
    <a class="cta" href="/signup">{{ page.data.cta }}</a>
</section>
<ul>
    {% for feature in page.data.features %}<li>{{ feature }}</li>{% endfor %}
</ul>
```

The `data` field is **not indexed** — it's only available when the full page is rendered. Editing happens in the admin's JSON editor on the page form.

## Page Image

The `image` field stores an image used for `og:image` social previews and as a hero image when the template renders one. It's a standard image-property field with shape transforms — same as any image field in T3.

```twig
<meta property="og:image" content="{{ cms.media.imagePath(page, 'shape:1200x630') }}">

{% if page.image.filename %}
<img src="{{ cms.media.imagePath(page, 'shape:1920x1080') }}" alt="{{ page.title }}">
{% endif %}
```

## HTTP Status Codes

Each page declares the HTTP status code it returns. The default is `200 OK`; other values change behavior:

| Status | Use Case |
|--------|----------|
| `200` | Standard published page (default) |
| `301` | Permanent redirect — see [Redirects](#redirects) |
| `302` | Temporary redirect |
| `404` | Universal Not Found page — see [Custom 404 Page](#custom-404-page) |
| `410` | Permanently removed content |
| `451` | Unavailable for legal reasons |
| `503` | Service unavailable (maintenance) |

The status flows through to the rendered response so search engines and clients see the right code.

## Custom 404 Page

To replace Slim's default 404 with a styled page from the admin, create a builder page and set its **HTTP Status** to `404`. Any URL that doesn't match a builder page or collection route renders this page — the same `status=404` field that drives the response code also marks the page as the universal fallback target.

Setup:

1. Create a builder page (e.g., title "Not Found", template `404`)
2. Set **HTTP Status** to `404`
3. The `route` field can be anything (e.g., `/404`) — it's the status that wires up the fallback, not the route

If multiple pages have status 404, the first one found wins. If no page has status 404, Slim's plain default is used.

## Redirects

Set a page's status to `301` (permanent) or `302` (temporary) and fill in **Redirect To** with the destination. The middleware sends a `Location` header instead of rendering the template:

| Field | Example |
|-------|---------|
| Route | `/old-page` |
| Status | `301 Moved Permanently` |
| Redirect To | `/new-page` |

`Redirect To` accepts an absolute URL (`https://example.com/x`) or a same-site path (`/new-page`).

## Collection URL Routing

The middleware also matches collection URL patterns. If a collection has a `url` field set (e.g., `/blog` with pretty URLs enabled), visiting `/blog/my-post` automatically:

1. Matches the URL to the blog collection
2. Fetches the `my-post` object
3. Renders `pages/{collection-id}.twig` (e.g., `pages/blog.twig`)
4. Passes the object data as `object` and any URL placeholders as `params`

This works with simple URLs (`/blog`), Slim-style placeholders (`/blog/{id}`), and Twig-style template URLs (`/blog/{{ category }}/{{ id }}`). Slim and Twig syntaxes are equivalent — they're normalized internally.

## Linking to Collection Objects

A common pattern is a **list page** that shows collection objects in a grid, linking to a **detail page** rendered from a collection URL. Two ways to wire this up:

### Approach 1: Builder page list + collection-URL detail

Create one builder page for the list, and let the collection's URL drive the detail view:

| Source | Route | Template | Purpose |
|--------|-------|----------|---------|
| Builder page | `/blog` | `blog-index` | Lists all posts (variable: `page`) |
| Collection (`blog`) URL | `/blog` | `pages/blog.twig` | Single post (variable: `object`) |

The `/blog` builder page handles the listing. Visiting `/blog/my-post` goes through the collection-URL match and renders `pages/blog.twig` with `object.*` populated. The list template uses `cms.collection.objectUrl()` to generate links:

```twig
{# pages/blog-index.twig — list page (uses `page`) #}
{% set posts = cms.collection.objects('blog') | sortCollectionByString('date:desc') %}
{% for post in posts %}
    <article>
        <h2><a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a></h2>
        <p>{{ post.summary }}</p>
    </article>
{% endfor %}
```

```twig
{# pages/blog.twig — detail page (uses `object`) #}
<h1>{{ object.title }}</h1>
<div>{{ object.content | raw }}</div>
```

### Approach 2: Two builder pages

Create both list and detail as builder pages. The detail page uses a dynamic route placeholder:

| Page | Route | Template |
|------|-------|----------|
| Blog | `/blog` | `blog-index` |
| Blog Post | `/blog/{id}` | `blog-post` |

Set the blog collection's URL to `/blog` (Pretty URLs enabled) so `objectUrl()` generates `/blog/my-post` matching the route.

```twig
{# pages/blog-post.twig — both `page` and `params.id` available #}
{% set post = cms.data.raw('blog', params.id) %}

{% if post %}
    <h1>{{ post.title }}</h1>
    <div>{{ post.content | raw }}</div>
{% else %}
    <h1>Post Not Found</h1>
{% endif %}
```

The first approach is simpler when the detail page only renders one collection. The second approach is useful when the detail page needs the builder-page record (`page.title`, `page.data.*`) alongside the collection object — useful for SEO overrides, custom metadata, etc.

See [Object Linking](docs/twig/object-linking) for full documentation on URL templates, canonical URLs, and the `objectUrl()` function.

## Pages Collection

Page metadata is stored in the `builder-pages` collection using the `builder-page` schema.

### Page Schema Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | slug | Page identifier (auto-generated from title) |
| `title` | text | Page title |
| `route` | text | URL pattern (e.g., `/about` or `/products/{id}`) |
| `template` | text | Page template name from `builder/pages/` (required) |
| `description` | textarea | Meta description |
| `image` | image | Page image — used for `og:image` and hero images |
| `data` | JSON | Free-form JSON exposed as `page.data.*` |
| `status` | select | HTTP status code returned (200, 301, 302, 404, 410, 451, 503) |
| `redirectTo` | text | Destination for 301/302 redirects |
| `draft` | toggle | Exclude from routing |
| `nav` | toggle | Include in navigation menus (default: true) |
| `sitemap` | toggle | Include in `sitemap.xml` (default: true) |
| `changeFrequency` | select | Sitemap change frequency hint |
| `priority` | number | Sitemap priority (0.0 to 1.0) |

> Hierarchy and ordering are stored separately in `tcms-data/{collection}/.order.json`, not as fields on the page record. See [Page Order](#page-order).

## Page Order

Page hierarchy and sort order live in a single file — `tcms-data/{collection}/.order.json` — instead of as `parent`/`sort` fields on each page. The file is a tree where each node is `{id, children: []}`:

```json
[
    {"id": "home", "children": []},
    {"id": "blog", "children": [
        {"id": "blog-post", "children": []}
    ]},
    {"id": "about", "children": []}
]
```

Order is implicit in the array index; hierarchy is implicit in the nesting. One small write replaces N page-record writes when reordering, and editing a page can never silently undo a reorder (the form doesn't carry that data).

The order file is reconciled against the page list on every read:

- IDs that no longer exist as pages are dropped
- Pages that exist but aren't in the order file are appended at the root
- If a parent page is deleted, its children are spliced into the parent's spot so grandchildren keep their structure

You don't edit `.order.json` directly — use the admin's drag-drop reorder mode (see [Builder Admin UI](docs/builder/admin#reordering-pages)). The file is created automatically on first read, migrating from any legacy `parent`/`sort` fields if present.

## Navigation

T3 provides Twig functions to build navigation menus from your pages collection. These functions automatically filter out draft pages and pages with `nav` set to false, and sort by the order file.

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

This is useful for pages like privacy policies, redirects, custom 404s, or dynamic sub-pages (e.g., `/blog/{id}`) that should be accessible but not listed in navigation.

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
- [Builder CLI Commands](docs/builder/cli) — `builder:init`, `builder:routes`, `builder:history`
- [Builder Admin UI](docs/builder/admin) — page management, drag-drop reorder, preview pane, snapshot history
- [Starter Templates](docs/builder/starters)
