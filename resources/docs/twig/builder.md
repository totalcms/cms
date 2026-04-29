---
title: "Twig Builder Reference"
description: "Reference for the cms.builder namespace providing navigation helpers for Site Builder pages."
since: "3.3.0"
---

# cms.builder

The builder adapter provides navigation helpers for Site Builder pages. All functions automatically filter out draft pages and pages with `nav` set to false, and sort results by the `sort` field (ascending).

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

## See Also

- [Site Builder Overview](docs/builder/overview)
- [Builder Admin UI](docs/builder/admin)
- [Collection Objects](docs/twig/collections)
