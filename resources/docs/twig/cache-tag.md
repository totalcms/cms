---
title: "Cache Tag Reference"
description: "Cache a rendered block of Twig with the {% cache %} tag — TTL plus optional collection tags that auto-invalidate when content changes, backed by the Total CMS cache backends."
related:
  - twig/cmsgrid-tag
  - twig/collections
---

# Cache Tag Reference

The `{% cache %}` tag stores the **rendered HTML** of a block so its body is skipped on subsequent requests. It is output (fragment) caching — different from Twig's template *compilation* cache, which only avoids re-parsing your `.twig` files but still re-runs them every request.

Use it for expensive fragments that change rarely: navigation menus, sidebars, related-post lists, `{% cmsgrid %}` listings, footers.

## Syntax

```twig
{% cache key ttl=seconds tags=['collection'] shared=bool %}
    {# expensive markup #}
{% endcache %}
```

### Parameters

- **`key`** (required) — a string expression identifying this fragment. Vary it by whatever the content depends on, e.g. `'sidebar:' ~ page.id`.
- **`ttl`** (optional) — lifetime in seconds. Defaults to the `cache.fragmentTtl` config value (3600).
- **`tags`** (optional) — a list of collection ids. When any object in a tagged collection is created, updated, or deleted, fragments carrying that tag are invalidated automatically — no manual cache clearing.
- **`shared`** (optional, default `false`) — see [Logged-in users](#logged-in-users).

## Examples

### Simple, time-based

```twig
{% cache 'home-promo' ttl=900 %}
    {{ cms.collection.objects('promos')|first.headline }}
{% endcache %}
```

Cached for 15 minutes, then re-rendered on the next request.

### Auto-invalidating with a collection tag

```twig
{% cache 'latest-posts' tags=['blog'] %}
    {% for post in cms.collection.objects('blog')|slice(0, 5) %}
        <a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a>
    {% endfor %}
{% endcache %}
```

This fragment is served from cache until any `blog` object changes, at which point it is rebuilt on the next request. The `ttl` still applies as a backstop.

### Per-page fragments

```twig
{% cache 'related:' ~ post.id tags=['blog'] %}
    {# related posts for this specific post #}
{% endcache %}
```

Each post gets its own cached fragment because the key varies by `post.id`.

## Logged-in users

By default, caching is **bypassed for authenticated requests**. A logged-in visitor always sees a freshly rendered fragment, and nothing personalized is ever stored under a shared key. This keeps access-group / member content from leaking between users.

If a fragment is identical for everyone (it does not depend on who is logged in), opt back into caching with `shared=true`:

```twig
{% cache 'global-footer' shared=true %}
    {# identical for all visitors #}
{% endcache %}
```

Only use `shared=true` when you are certain the markup is the same for anonymous and logged-in visitors alike.

## How invalidation works

Each tag has an internal version counter. A fragment's storage key embeds the current version of its tags, so when content in a tagged collection changes, the counter is bumped and every fragment keyed on the old version is instantly bypassed (the orphaned entries expire on their own TTL). This is automatic — you never call a clear function.

## Notes

- Fragments are stored in the normal Total CMS cache (APCu → Redis → Memcached → filesystem) and are wiped by any global cache clear (devmode, `/api/emergency/cache/clear`).
- Caching is skipped automatically in **devmode** so you always see fresh output while editing.
- Set `cache.fragments` to `false` in config to disable the tag globally; `cache.fragmentTtl` sets the default lifetime.
- If a cache backend errors, the block falls back to a live render — it never breaks the page.
