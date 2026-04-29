---
title: "Twig Builder Reference"
description: "Reference for the cms.builder namespace providing navigation helpers and asset management for Site Builder pages."
since: "3.3.0"
---

# cms.builder

The builder adapter provides navigation helpers and asset management for Site Builder sites. Navigation functions automatically filter out draft pages and pages with `nav` set to false, and sort results by the `sort` field (ascending). Asset functions handle path resolution and cache busting.

## nav()

Get top-level navigation pages (pages with no parent).

```twig
{% set pages = cms.builder.nav() %}
{% for p in pages %}
    <a href="{{ p.route }}">{{ p.title }}</a>
{% endfor %}
```

Returns a flat array of page objects from the configured pages collection, filtered to only include pages where:

- `draft` is `false`
- `nav` is `true` (or missing — defaults to `true` for backwards compatibility)
- `parent` is empty

### Custom Collection

Pass a collection ID to use a different pages collection:

```twig
{% set pages = cms.builder.nav('my-custom-pages') %}
```

### Return Value

`array` — Each element is a page object with all indexed fields:

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Page identifier |
| `title` | string | Page title |
| `route` | string | URL path (e.g., `/about`) |
| `template` | string | Template name from `builder/pages/` |
| `layout` | string | Layout template name |
| `description` | string | Meta description |
| `draft` | boolean | Always `false` (drafts are filtered out) |
| `nav` | boolean | Always `true` (nav-hidden pages are filtered out) |
| `sort` | number | Sort order |
| `parent` | string | Parent page ID (always empty for `nav()` results) |

---

## subnav()

Get child pages of a specific parent.

```twig
{% set children = cms.builder.subnav('blog') %}
{% for p in children %}
    <a href="{{ p.route }}">{{ p.title }}</a>
{% endfor %}
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `parentId` | string | yes | The `id` of the parent page |
| `collection` | string | no | Custom collection ID (defaults to configured pages collection) |

### Example: Section Sub-Navigation

```twig
{# Main nav #}
<nav>
    {% for p in cms.builder.nav() %}
        <a href="{{ p.route }}">{{ p.title }}</a>
    {% endfor %}
</nav>

{# Sub-nav for the current section #}
{% set children = cms.builder.subnav('services') %}
{% if children is not empty %}
<nav class="subnav">
    {% for p in children %}
        <a href="{{ p.route }}">{{ p.title }}</a>
    {% endfor %}
</nav>
{% endif %}
```

---

## navTree()

Get the full navigation hierarchy as a nested tree.

```twig
{% set tree = cms.builder.navTree() %}
```

Returns top-level pages with a `children` key containing their child pages, recursively nested.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `collection` | string | no | Custom collection ID (defaults to configured pages collection) |

### Return Structure

Each page in the tree has all the standard page fields plus a `children` array:

```
[
    {id: "home", title: "Home", route: "/", children: []},
    {id: "services", title: "Services", route: "/services", children: [
        {id: "web-design", title: "Web Design", route: "/services/web-design", children: []},
        {id: "seo", title: "SEO", route: "/services/seo", children: []},
    ]},
    {id: "about", title: "About", route: "/about", children: []},
]
```

### Example: Two-Level Navigation

```twig
<nav>
    {% for p in cms.builder.navTree() %}
        <a href="{{ p.route }}">{{ p.title }}</a>
        {% if p.children is not empty %}
        <ul>
            {% for child in p.children %}
                <li><a href="{{ child.route }}">{{ child.title }}</a></li>
            {% endfor %}
        </ul>
        {% endif %}
    {% endfor %}
</nav>
```

### Example: Recursive Navigation Macro

For deeply nested menus, use a Twig macro:

```twig
{% macro navItems(pages) %}
    {% for p in pages %}
        <li>
            <a href="{{ p.route }}">{{ p.title }}</a>
            {% if p.children is not empty %}
            <ul>
                {{ _self.navItems(p.children) }}
            </ul>
            {% endif %}
        </li>
    {% endfor %}
{% endmacro %}

<nav>
    <ul>
        {{ _self.navItems(cms.builder.navTree()) }}
    </ul>
</nav>
```

---

## asset()

Resolve an asset path to a URL with automatic cache busting.

```twig
{{ cms.builder.asset('images/hero.webp') }}
{# Output: /assets/images/hero.webp?v=1714300000 #}
```

Use this when you need the raw URL — for background images, `srcset`, custom attributes, or any case where `css()`/`js()` don't fit.

### How Resolution Works

1. **Manifest exists** — reads `manifest.json` from the assets directory and resolves hashed filenames (e.g., `style.css` → `style.a1b2c3.css`)
2. **No manifest, file exists** — appends `?v={mtime}` for cache busting via file modification time
3. **File not found** — returns the raw path with no cache busting

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | yes | Asset path relative to the assets directory |

---

## css()

Output a `<link rel="stylesheet">` tag for a CSS file.

```twig
{{ cms.builder.css('style.css') }}
{{ cms.builder.css('vendor/normalize.css') }}
```

Output:
```html
<link rel="stylesheet" href="/assets/style.css?v=1714300000">
<link rel="stylesheet" href="/assets/vendor/normalize.css?v=1714300000">
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | yes | CSS file path relative to the assets directory |

---

## js()

Output a `<script>` tag for a JavaScript file.

```twig
{{ cms.builder.js('app.js') }}
{{ cms.builder.js('app.js', {module: true}) }}
```

Output:
```html
<script src="/assets/app.js?v=1714300000"></script>
<script type="module" src="/assets/app.js?v=1714300000"></script>
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | yes | JS file path relative to the assets directory |
| `options` | object | no | Options: `module` (bool) adds `type="module"` attribute |

---

## preload()

Output a `<link rel="preload">` tag for preloading assets. Automatically adds the `crossorigin` attribute for fonts (required by browsers).

```twig
{{ cms.builder.preload('fonts/inter.woff2', 'font') }}
{{ cms.builder.preload('hero.webp', 'image') }}
{{ cms.builder.preload('app.js', 'script') }}
```

Output:
```html
<link rel="preload" href="/assets/fonts/inter.woff2?v=1714300000" as="font" crossorigin>
<link rel="preload" href="/assets/hero.webp?v=1714300000" as="image">
<link rel="preload" href="/assets/app.js?v=1714300000" as="script">
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | yes | Asset path relative to the assets directory |
| `as` | string | yes | Resource type: `font`, `image`, `script`, `style`, `fetch` |

---

## Asset Configuration

### Assets Directory

By default, assets are served from the `assets/` directory in your docroot. Configure a different path in **Admin > Settings > Builder**:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Assets Path | text | `assets` | Public assets directory relative to docroot |

Your build tool should output files to this directory. The web server (Apache/Nginx) serves them as static files — T3 only generates the URLs.

### Build Tool Manifest

For production builds with content-hashed filenames, output a `manifest.json` to your assets directory. The asset functions will automatically resolve hashed filenames from the manifest.

#### Vite

```js
// vite.config.js
export default {
    build: {
        manifest: true,
        outDir: 'assets'
    }
}
```

#### esbuild

```js
// build.js
require('esbuild').build({
    entryPoints: ['src/app.js', 'src/style.css'],
    outdir: 'assets',
    metafile: true,
    // Use a plugin to write manifest.json
})
```

When a manifest is present, hashed filenames are used instead of mtime query strings:

```html
<!-- Without manifest -->
<link rel="stylesheet" href="/assets/style.css?v=1714300000">

<!-- With manifest -->
<link rel="stylesheet" href="/assets/style.a1b2c3.css">
```

Templates don't change between development and production — the asset functions handle resolution automatically.

### Example: Complete Layout

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}{{ page.title }}{% endblock %}</title>

    {{ cms.builder.preload('fonts/inter.woff2', 'font') }}
    {{ cms.builder.css('style.css') }}
</head>
<body>
    {% include 'partials/nav.twig' %}

    <main>{% block content %}{% endblock %}</main>

    {% include 'partials/footer.twig' %}

    {{ cms.builder.js('app.js', {module: true}) }}
</body>
</html>
```

---

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder Admin UI](docs/builder/admin)
- [Collection Objects](docs/twig/collections)
