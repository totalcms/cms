---
title: "Twig Views Reference"
description: "Reference for the cms.view namespace providing access to pre-computed data views."
---

# cms.view

The view adapter provides access to data views — pre-computed, cached queries that aggregate data across collections.

## Methods

### get()

Get the pre-computed data from a data view by its ID.

```twig
{% set recentPosts = cms.view.get('recent-posts') %}
{% for post in recentPosts %}
    <article>
        <h2>{{ post.title }}</h2>
        <p>{{ post.excerpt }}</p>
    </article>
{% endfor %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `viewId` | string | Data view identifier |

**Returns:** `array` — the view's computed result data

### query()

Get one page of a data view, filtered and sorted. The view's output must be a list of objects. It takes the same options as [`cms.collection.query()`](docs/twig/collections#query) and returns the same result: `objects`, `total`, `limit`, `offset`, `page`, `pages` and `hasMore`.

```twig
{% set result = cms.view.query('top-posts', {
    filter: [{property: 'score', operator: 'gt', value: 4}],
    sort: 'score:desc',
    limit: 10,
    page: params.page|default(1)
}) %}
{% for post in result.objects %}
    <h2>{{ post.title }}</h2>
{% endfor %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `viewId` | string | Data view identifier |
| `options` | object | `filter`, `sort`, `limit`, `page` or `offset`, as for `cms.collection.query()` |

The page is cached until the view is rebuilt or any collection is saved. Like `get()`, it reads the view's saved output: a view that has not been built yet returns an empty result until its first build. A view whose output is not a list of objects, such as a single stats object, also returns an empty result; use `get()` for those.

### list()

List all available data views.

```twig
{% set views = cms.view.list() %}
{% for view in views %}
    <p>{{ view.id }} — {{ view.name }}</p>
{% endfor %}
```

**Returns:** `array` — all data view definitions
