---
title: "HTMX Recipes"
description: "Live search, faceted filtering, lazy sections, polling widgets, quick-view modals, zero-JavaScript forms, likes and boosted navigation — all from the Total CMS API and the htmx that already ships on every page."
related:
  - twig/load-more
  - twig/render
  - forms/overview
  - collections/settings
audience: intermediate
updated: 2026-09-06
---

# HTMX Recipes

Every collection and Data View query in Total CMS can return **rendered HTML**
instead of JSON. Add `format=html` and a `template` and the API renders each
matching object through a template from `builder/templates/` and returns the
markup. Load More is one consumer of that endpoint. This page is the rest of
what it does.

Nothing here needs JavaScript of your own. htmx is emitted by
`cms.assetsBody()`, the fragment templates are ordinary Twig files you edit
in the admin Templates screen, and the helpers below build the URLs.

## The primitive

```twig
{# raw form, so you can see what the helpers produce #}
/api/collections/blog/query?format=html&template=blog/card&limit=12&search=php
```

`search`, `include`, `exclude`, `sort`, `limit` and `offset` all work exactly
as they do for JSON. Prefer the helpers, which build and escape the URL:

| Helper | Returns |
| --- | --- |
| `cms.render.queryUrl('blog', {template: 'blog/card', …})` | a collection query fragment URL |
| `cms.render.viewQueryUrl('recent', {template: 'cards/item', …})` | a Data View query fragment URL |
| `cms.render.objectFragmentUrl('blog', id, {template: 'blog/quick'})` | one object rendered through a template |
| `cms.render.saveUrl('contact', {template: 'forms/thanks'})` | an object write that answers htmx with a rendered template |
| `cms.render.incrementUrl('posts', id, 'likes')` | a counter write |

Template ids are relative to `builder/templates/`; each template receives
`object` and `collection`. Anonymous visitors can only reach collections
whose [`publicOperations`](docs/collections/settings) allow it, and counters
whose field has `publicIncrement` set.

## Live search

```twig
<input type="search" name="search" placeholder="Search posts"
       hx-get="{{ cms.render.queryUrl('blog', {template: 'blog/card', limit: 12}) }}"
       hx-trigger="input changed delay:300ms, search"
       hx-target="#results">
<div id="results">
  {% for post in cms.collection.objects('blog')|slice(0, 12) %}{% include 'templates/blog/card.twig' with {object: post} %}{% endfor %}
</div>
```

htmx sends the input's value as `search`, appended to the helper's URL. The
first page is rendered server-side so the list is there before anyone types.

## Faceted filtering

Selects whose values are `include` filters. Each change re-fetches with
every control's value included.

```twig
<form hx-get="{{ cms.render.queryUrl('products', {template: 'products/card', limit: 24}) }}"
      hx-trigger="change" hx-target="#products" hx-include="this">
  <select name="include">
    <option value="">All categories</option>
    <option value="category:audio">Audio</option>
    <option value="category:video">Video</option>
  </select>
  <select name="sort">
    <option value="-date">Newest</option>
    <option value="price">Price, low to high</option>
  </select>
</form>
<div id="products"></div>
```

Combine facets by joining filters with a comma in the value, for example
`category:audio,featured:true`.

## Sort toggles and view switches

Two links, two templates, one target:

```twig
<a hx-get="{{ cms.render.queryUrl('blog', {template: 'blog/card', limit: 12}) }}" hx-target="#feed">Cards</a>
<a hx-get="{{ cms.render.queryUrl('blog', {template: 'blog/row',  limit: 12}) }}" hx-target="#feed">List</a>
<div id="feed"></div>
```

## Lazy sections

Keep the page light and load the parts that vary after the fold:

```twig
<section hx-get="{{ cms.render.queryUrl('blog', {template: 'blog/related', include: 'tags:' ~ post.tags|first, limit: 3}) }}"
         hx-trigger="revealed" hx-swap="innerHTML">
  <p class="placeholder">Loading related posts…</p>
</section>
```

## Polling widgets from a Data View

A materialised Data View is cheap to re-fetch, so a status board can poll:

```twig
<div hx-get="{{ cms.render.viewQueryUrl('scores-today', {template: 'widgets/score', limit: 10}) }}"
     hx-trigger="load, every 60s" hx-swap="innerHTML"></div>
```

## Boosted navigation on Site Builder

Site Builder pages are complete HTML documents, so `hx-boost` gives
in-page navigation with view transitions and no other change:

```twig
<body hx-boost="true">
```

Three caveats. Scripts inside the swapped content do not re-run, so keep
`cms.assetsBody()` in the layout rather than a page. Opt individual links out
with `hx-boost="false"`, in particular downloads and external links. And test
any page that relies on `DOMContentLoaded` running once.

## Member-only fragments

A public page can carry one fragment that only logged-in members see, by
fetching from a collection protected by [access groups](docs/auth/access-groups):

```twig
<div hx-get="{{ cms.render.queryUrl('member-news', {template: 'members/news', limit: 5}) }}"
     hx-trigger="load" hx-swap="innerHTML">
  <p>Loading…</p>
</div>
```

A visitor who is not signed in receives a 403 rendered as an error fragment
(see [Errors](#errors)), which swaps in where the news would be; style
`.cms-error-403` as a sign-in prompt. The page itself stays fully cacheable,
because it is identical for everyone.

## Quick-view modal for one object

`GET /api/collections/{collection}/{id}?format=html&template=…` renders one
object. Wire it to a dialog:

```twig
{% for product in cms.collection.objects('products') %}
  <button hx-get="{{ cms.render.objectFragmentUrl('products', product.id, {template: 'products/quick-view'}) }}"
          hx-target="#quick" hx-swap="innerHTML"
          onclick="document.getElementById('quick-dialog').showModal()">
    {{ product.title }}
  </button>
{% endfor %}
<dialog id="quick-dialog"><div id="quick"></div></dialog>
```

## A contact form with no JavaScript

The object save endpoint answers an htmx request that names a `template`
with that template rendered against the saved object. Validation failures
come back as an error fragment listing the fields.

```twig
<form hx-post="{{ cms.render.saveUrl('contact', {template: 'forms/thanks'}) }}"
      hx-target="this" hx-swap="outerHTML">
  <input name="email" type="email" required>
  <textarea name="message" required></textarea>
  <button>Send</button>
</form>
```

`forms/thanks.twig` might be `<p class="thanks">Thanks, {{ object.email }} — we'll reply soon.</p>`.
The `contact` collection needs `create` in its `publicOperations`. For
deferred uploads, post-save actions and spam protection, use the
[form builder](docs/forms/builder) instead; this recipe is the simple path.

## Newsletter signup through a webhook

A synchronous [automation webhook](docs/automations/webhooks) whose handler
returns a string answers an htmx request with that string as HTML:

```twig
<form hx-post="/automations/newsletter" hx-target="this" hx-swap="outerHTML">
  <input name="email" type="email" required>
  <button>Subscribe</button>
</form>
```

```php
// the automation's handler
return function ($ctx) {
    // …add $ctx->args['email'] to your list…
    return '<p class="ok">Check your inbox to confirm.</p>';
};
```

Set the trigger to `sync: true`. A handler that throws produces an error
fragment instead.

## Likes and "was this helpful"

The increment endpoint adds to a number property. Open one field to the
public with `publicIncrement: true` in its settings, which grants nothing
else on the object (see [Number and Range Fields](docs/fields/number-range)):

```twig
<button hx-post="{{ cms.render.incrementUrl('posts', post.id, 'likes') }}"
        hx-swap="none"
        hx-on:htmx:after:request="this.disabled = true">
  ♥ {{ post.likes }}
</button>
```

Anonymous callers are limited to 60 increments a minute per IP. It is a soft
signal, not a vote: anyone can call it, and the number is only as honest as
the crowd.

## Errors

htmx swaps error responses like any other. When a request carries the
`HX-Request` header, API errors come back as HTML rather than JSON:

```html
<div class="cms-error cms-error-403" role="alert"><p>403 Forbidden</p></div>
```

A validation failure lists its fields, so a form can highlight them:

```html
<div class="cms-error cms-error-400" role="alert">
  <p>Please fix the highlighted fields.</p>
  <ul class="cms-error-fields"><li data-field="email">…</li></ul>
</div>
```

The `.cms-error` styles ship in the same stylesheet as Load More. Add
`hx-on:htmx:response:error` to an element only if you want to do something
extra, such as log or announce the failure.
