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
| `sort` | number | Navigation ordering |
| `parent` | select | Hierarchical parent page |

## URL Structure

T3 uses the following URL structure:

| Path | Purpose |
|------|---------|
| `/api/*` | REST API endpoints (collections, schemas, templates, etc.) |
| `/admin/*` | Admin dashboard and auth pages (login, logout, etc.) |
| `/setup/*` | Setup wizard |
| `/*` | Builder pages and collection URLs |

API routes always take priority over builder pages.

## Coexistence with Stacks

For Stacks sites where T3 is installed at a subpath, Stacks-published pages are static PHP files that serve directly. Configure your `.htaccess` to route unmatched requests to T3:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ /path/to/tcms [QSA,L]
```

## See Also

- [Builder CLI Commands](docs/builder/cli)
- [Builder Admin UI](docs/builder/admin)
- [Starter Templates](docs/builder/starters)
