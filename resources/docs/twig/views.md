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

### list()

List all available data views.

```twig
{% set views = cms.view.list() %}
{% for view in views %}
    <p>{{ view.id }} — {{ view.name }}</p>
{% endfor %}
```

**Returns:** `array` — all data view definitions
