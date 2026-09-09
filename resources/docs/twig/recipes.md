---
title: "Twig Recipes"
description: "Ready-to-paste Twig patterns for Total CMS: render stored content as Twig, archives, tag clouds, related posts, prev/next, scheduled publishing, calendars, SEO heads for detail pages, extra JSON-LD types, obfuscated contact details, daily picks, fragment caching, member-only blocks and signed links."
related:
  - twig/htmx
  - twig/filters
  - twig/functions
  - twig/collection-filtering
  - twig/cache-tag
  - site-builder/seo
audience: intermediate
updated: 2026-09-09
---

# Twig Recipes

The rest of the Twig section is organised by primitive: one page per filter
family, one per adapter. This page is organised by outcome. Each recipe is a
problem you are likely to hit, the template that solves it, and a note or two
on why it is written that way. Every helper used here is documented in
[Filters](docs/twig/filters), [Functions](docs/twig/functions),
[Collection Filtering](docs/twig/collection-filtering) or the adapter pages,
so follow the links when you want the full option list.

Recipes that fetch content from the server without a page reload live on
[HTMX Recipes](docs/twig/htmx). Everything here renders on the server.

## Content stored in the CMS

### Render a stored text field as Twig

Sometimes the *content* needs Twig: a landing-page body that lists the
three newest products, a footer note that prints the current year, a
snippet an editor maintains that should call a macro. Total CMS registers
Twig's string loader, so any string can be compiled and rendered on the fly
with `template_from_string`:

```twig
{% set page = cms.collection.object('pages', 'about') %}

{% if page.body %}
  {{ include(template_from_string(page.body)) }}
{% endif %}
```

- The stored string sees everything the calling template sees, `cms`
  included, so `{{ cms.collection.objects('products')|slice(0, 3) }}` inside
  the field just works.
- Pass extra variables as the second argument:
  `{{ include(template_from_string(page.body), {page: page}) }}`.
- `include()` returns safe HTML, so no `|raw` is needed. Run the field
  through `|markdown` first if it is Markdown that also contains Twig:
  `template_from_string(page.body|markdown)`.
- A Twig syntax error in the field is a render error on the page. Only
  expose this to editors you trust with template access, and keep the
  `{% if %}` guard so an empty field renders nothing rather than failing.

### A snippets collection editors own

Give editors a `snippets` collection with `id` and `body` fields, and pull
snippets into any template by id. The body is rendered as Twig, so a snippet
can be static HTML, a macro call or a small loop, and it is edited in the
admin rather than in a template file:

```twig
{% macro snippet(id, vars = {}) %}
  {% set item = cms.collection.object('snippets', id) %}
  {% if item.body %}
    {{ include(template_from_string(item.body), vars) }}
  {% endif %}
{% endmacro %}

{% import _self as ui %}

{{ ui.snippet('promo-banner') }}
{{ ui.snippet('pricing-note', {plan: 'pro'}) }}
```

Missing snippets render nothing, so a template never breaks because an
editor deleted one. Wrap a snippet that queries collections in a
[`{% cache %}`](docs/twig/cache-tag) tag when it appears on every page.

### Pick a partial from a field value

A `layout` select field on the object (say `hero`, `split`, `quote`) can
choose which partial renders it. Twig's `include` function with
`ignore_missing` falls back cleanly when no partial exists for a value:

```twig
{% for block in cms.collection.objects('blocks') %}
  {{ include('blocks/' ~ (block.layout|default('default')) ~ '.twig', {block: block}, with_context = false, ignore_missing = true) }}
{% endfor %}
```

Add a new layout by adding an option to the field and a matching partial.
Templates never grow an `if/elseif` chain. `with_context = false` keeps the
partial's inputs explicit: it receives only `block` and the `cms` global.

## Lists, archives and navigation

### Year archive with counts

Sort by date descending and print a heading whenever the year changes.
No grouping filter is needed, and the list stays a single loop:

```twig
{% set posts = cms.collection.objects('blog')|sortCollection([{property: 'date', reverse: true}]) %}
{% set year = null %}

{% for post in posts %}
  {% set postYear = post.date|date('Y') %}
  {% if postYear != year %}
    {% if year is not null %}</ul>{% endif %}
    {% set year = postYear %}
    <h2>{{ year }}</h2>
    <ul>
  {% endif %}
  <li><a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a></li>
{% endfor %}
{% if year is not null %}</ul>{% endif %}
```

For a count next to each year, build the totals first:

```twig
{% set totals = {} %}
{% for post in posts %}
  {% set y = post.date|date('Y') %}
  {% set totals = totals|merge({(y): (totals[y]|default(0)) + 1}) %}
{% endfor %}

{% for y, n in totals %}<a href="/blog/{{ y }}">{{ y }} ({{ n }})</a>{% endfor %}
```

When the property you are grouping on is a plain value rather than a date,
`groupBy` and `countBy` do this in one call: `posts|countBy('category')`.

### Tag cloud

Tags live in an array field, so flatten them with a `merge` loop, count them,
then size each link by frequency:

```twig
{% set counts = {} %}
{% for post in cms.collection.objects('blog') %}
  {% for tag in post.tags|default([]) %}
    {% set counts = counts|merge({(tag): (counts[tag]|default(0)) + 1}) %}
  {% endfor %}
{% endfor %}

{% if counts %}
  {% set most = max(counts) %}
  <p class="tag-cloud">
  {% for tag, n in counts|ksort %}
    <a href="/blog?include=tags:{{ tag|url_encode }}"
       class="{{ tag|prefixSlug('tag-') }}"
       style="font-size: {{ 0.8 + (n / most) * 1.2 }}rem">{{ tag }}</a>
  {% endfor %}
  </p>
{% endif %}
```

The link format is the one [`cms.utils.urlFilters()`](docs/twig/utils) reads,
so the target page can filter on it with no extra code. See the
query-string recipe below.

### Related posts by shared tags

Match any of the current post's tags, drop the post itself, then take the
newest few:

```twig
{% set related = cms.collection.objects('blog')|filterCollection([
  {property: 'tags', operator: 'contains', value: post.tags},
  {property: 'id',   operator: 'not-equal', value: post.id}
])|sortCollection([{property: 'date', reverse: true}])|slice(0, 3) %}

{% if related %}
  <aside>
    <h3>Related</h3>
    {% for item in related %}
      <a href="{{ cms.collection.objectUrl('blog', item) }}">{{ item.title }}</a>
    {% endfor %}
  </aside>
{% endif %}
```

An array `value` matches **any** tag by default. Pass `logic: 'and'` to
require every tag, which gives fewer but closer matches.

### Previous and next links

Sort once, then let `prev` and `next` find the neighbours of the current id.
The third argument wraps around at the ends:

```twig
{% set ordered = cms.collection.objects('blog')|sortCollection([{property: 'date'}]) %}
{% set older = prev(ordered, post.id, true) %}
{% set newer = next(ordered, post.id, true) %}

<nav class="post-nav">
  <a href="{{ cms.collection.objectUrl('blog', older) }}">&larr; {{ older.title }}</a>
  <a href="{{ cms.collection.objectUrl('blog', newer) }}">{{ newer.title }} &rarr;</a>
</nav>
```

Whatever order you sort by is the order the links follow, so the same
recipe gives alphabetical neighbours for a glossary or manual-sort
neighbours for a portfolio.

### Featured first, then the rest

One sort, two keys. Booleans sort with `true` first when reversed, so
featured posts float to the top and date breaks the tie:

```twig
{% set posts = cms.collection.objects('blog')|sortCollection([
  {property: 'featured', reverse: true},
  {property: 'date',     reverse: true}
]) %}

{% for post in posts %}
  <article class="{{ post.featured ? 'is-featured' }}">
    <h2>{{ post.title }}</h2>
  </article>
{% endfor %}
```

### One stream from two collections

News and events on the same page, newest first. Tag each object with its
source before merging so the loop can render and link them differently:

```twig
{% set items = [] %}
{% for post in cms.collection.objects('news') %}
  {% set items = items|merge([post|merge({_type: 'news'})]) %}
{% endfor %}
{% for event in cms.collection.objects('events') %}
  {% set items = items|merge([event|merge({_type: 'event'})]) %}
{% endfor %}

{% for item in items|sortCollection([{property: 'date', reverse: true}])|slice(0, 10) %}
  <a class="{{ item._type }}" href="{{ cms.collection.objectUrl(item._type, item) }}">
    <time>{{ item.date|date('M j') }}</time> {{ item.title }}
  </a>
{% endfor %}
```

This only works when both collections share the property you sort on. If
one calls it `date` and the other `starts`, normalise it in the same
`merge` that adds `_type`.

### Query-string filters with no JavaScript

`?include=category:design&sort=date:desc` in the URL becomes a filtered,
sorted list. `cms.utils.urlFilters()` parses the query string into the same
`include`, `exclude`, `sort` and `search` options the API uses:

```twig
{% set filters = cms.utils.urlFilters() %}

<form method="get">
  <input type="search" name="search" value="{{ filters.search }}">
  <select name="sort">
    <option value="date:desc">Newest</option>
    <option value="title:asc">A to Z</option>
  </select>
  <button>Apply</button>
</form>

{{ cms.render.loadMore('blog', {
  template: 'blog/card',
  include:  filters.include,
  exclude:  filters.exclude,
  sort:     filters.sort,
  search:   filters.search
}) }}
```

Because the state lives in the URL, results are bookmarkable and
shareable, and the tag-cloud links above land on a working filtered page.
For the same thing without a reload, see
[faceted filtering](docs/twig/htmx#faceted-filtering).

## Dates and scheduling

### Scheduled publishing

Give the schema a `publish` date field and a `draft` toggle. The list shows
only what is both published and due, so an editor can write next week's
post today:

```twig
{% set live = cms.collection.objects('blog')|filterCollection([
  {property: 'draft',   operator: 'isfalse'},
  {property: 'publish', operator: 'pastToday'}
])|sortCollection([{property: 'publish', reverse: true}]) %}
```

Apply the same two filters on the single-object page and redirect when
they exclude it, otherwise a guessed URL still reveals the post:

```twig
{% set post = cms.collection.object('blog', getData.id) %}
{% if post.draft or post.publish|dateIsFuture %}{% set post = [] %}{% endif %}
{{ cms.collection.redirectIfNotFound(post) }}
```

Page caches do not know a date has passed. If you cache the list with
`{% cache %}`, keep the `ttl` short enough that a scheduled post appears
on time.

### Upcoming events with relative dates

`todayPlusDays` gives a rolling window, and `dateRelative` turns the date
into "in 3 days":

```twig
{% set upcoming = cms.collection.objects('events')|filterCollection([
  {property: 'date', operator: 'todayPlusDays', value: 30}
])|sortCollection([{property: 'date'}]) %}

{% for event in upcoming %}
  <article>
    <h3>{{ event.title }}</h3>
    <time datetime="{{ event.date|date('c') }}">
      {{ event.date|date('l, F j') }} &middot; {{ event.date|dateRelative }}
    </time>
  </article>
{% else %}
  <p>Nothing scheduled in the next month.</p>
{% endfor %}
```

For events that recur on the same day each month (a meetup, a billing
date), store the first occurrence and project it into the current month
with `recurringMonthDate`, which clamps the 31st to shorter months:

```twig
<p>Next meetup: {{ group.firstMeeting|recurringMonthDate|date('F j') }}</p>
{% if group.firstMeeting|dateIsRecurringDate %}<strong>That is today.</strong>{% endif %}
```

### A month calendar grid

Build a seven-column grid for the month in `?m=2026-09` (defaulting to
now), and drop each event into its day. Events are indexed by day first so
the grid loop stays cheap:

```twig
{% set month = (getData.m|default('now'))|dateStartOf('month') %}
{% set first = month|date('N') - 1 %}        {# blank cells before day 1, Monday-based #}
{% set days  = month|date('t') %}

{% set byDay = {} %}
{% for event in cms.collection.objects('events')|filterCollection([
  {property: 'date', operator: 'after',  value: month|dateSubtract('1 day')},
  {property: 'date', operator: 'before', value: month|dateAdd('1 month')}
]) %}
  {% set d = event.date|date('j') %}
  {% set byDay = byDay|merge({(d): (byDay[d]|default([]))|merge([event])}) %}
{% endfor %}

<h2>{{ month|date('F Y') }}</h2>
<nav>
  <a href="?m={{ month|dateSubtract('1 month')|date('Y-m') }}">&larr;</a>
  <a href="?m={{ month|dateAdd('1 month')|date('Y-m') }}">&rarr;</a>
</nav>

<div class="calendar">
  {% if first > 0 %}{% for _ in 1..first %}<div class="pad"></div>{% endfor %}{% endif %}
  {% for day in 1..days %}
    <div class="day">
      <span>{{ day }}</span>
      {% for event in byDay[day]|default([]) %}
        <a href="{{ cms.collection.objectUrl('events', event) }}">{{ event.title }}</a>
      {% endfor %}
    </div>
  {% endfor %}
</div>
```

```css
.calendar { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
```

The `first > 0` guard matters: Twig's `1..0` range counts *down* and would
emit two padding cells when the month starts on a Monday.

## Presentation helpers

### Reading time and word count

```twig
<p class="meta">
  {{ post.content|striptags|wordcount }} words &middot;
  {{ post.content|striptags|readtime|round }} min read
</p>
```

`readtime` assumes 180 words per minute; pass a number to change it.
Strip tags first when the field is rich text, or markup inflates the count.

### The `<head>` for a collection detail page

Core SEO writes the head for you. A layout carries one call, and a detail
page overrides the block to describe the object it renders rather than the
page record that routes to it:

```twig
{# layouts/default.twig #}
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  {% block seo %}{{ cms.seo.head(page|default(null)) }}{% endblock %}
  {{ cms.assetsHead() }}
</head>
```

```twig
{# pages/blog/post.twig #}
{% extends 'layouts/default.twig' %}
{% set post = cms.collection.object('blog', params.id) %}

{% block seo %}{{ post ? cms.seo.head(post, {collection: 'blog'}) : cms.seo.head(page|default(null)) }}{% endblock %}
```

That gives the post its own title, meta description, canonical, Open Graph
and Twitter tags, and an Article node in the JSON-LD graph. Values come
from the object's SEO card, then the collection's field mapping, then the
Site SEO record, so nothing on the object is required for it to work.

- **Always pass `collection`.** An object array does not know where it came
  from, and without it the mapping, the URL and the image cannot resolve.
- **Set the collection's URL first.** No URL means no canonical, no
  `og:url` and no Article node. The reserved `blog` collection ships
  without one.
- **Delete any `<title>` or description meta the layout wrote by hand**, or
  the page ships two of each.
- The pieces are available separately as `cms.seo.title()`, `meta()`,
  `og()`, `canonical()` and `jsonld()` when a layout places them itself.

Full reference: [SEO](docs/site-builder/seo).

### JSON-LD for types core does not emit

Core emits Organization, WebSite, WebPage, BreadcrumbList and Article. A
product, event, recipe or FAQ page wants its own node. Keep `cms.seo.head()`
for everything it covers and add a second `<script>` for the extra type,
built as a Twig map and handed to `json_encode`:

```twig
{% block seo %}
  {{ cms.seo.head(product, {collection: 'products'}) }}
  {% set ld = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.title,
    description: product.summary|striptags,
    image: 'https://' ~ cms.domain ~ cms.media.imagePath(product, {w: 1200}, {collection: 'products', property: 'image'}),
    sku: product.sku,
    offers: {
      '@type': 'Offer',
      price: product.price,
      priceCurrency: 'USD',
      availability: product.inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      url: 'https://' ~ cms.domain ~ cms.collection.objectUrl('products', product)
    }
  } %}
  <script type="application/ld+json">{{ ld|json_encode(constant('JSON_UNESCAPED_SLASHES'))|raw }}</script>
{% endblock %}
```

The output goes inside a `<script>`, so it must be `|raw`; `json_encode`
has already made it safe for that context. Swap `Product` for `Event`,
`Recipe` or `FAQPage` and fill in the properties that type expects, then
validate the page once at validator.schema.org.

### Contact details that bots cannot harvest

`mailto` writes the address into the page obfuscated and decodes it on
the client, so the plain address never appears in the HTML source:

```twig
{{ contact.email|mailto('Website enquiry', '', 'Email ' ~ contact.name) }}
```

Phone numbers are not scraped the same way, but they benefit from
consistent formatting and a `tel:` link built from digits only:

```twig
<a href="tel:+1{{ contact.phone|digitsOnly }}">{{ contact.phone|formatPhone }}</a>
```

For any other value you want out of casual view without real encryption,
`obfuscate` and `deobfuscate` are a matched, reversible pair.

### A daily pick that does not change on reload

`shuffle` gives a different item on every request, which looks broken on a
"quote of the day". Index by the day of the year instead, and the pick is
stable for 24 hours and rotates through the whole collection over time:

```twig
{% set quotes = cms.collection.objects('quotes') %}
{% if quotes %}
  {% set today = 'now'|date('z') %}
  {% set pick  = quotes[today % (quotes|length)] %}
  <blockquote>{{ pick.text }} <cite>{{ pick.author }}</cite></blockquote>
{% endif %}
```

Use `'now'|date('W')` for a weekly rotation, or `'now'|date('N') - 1` for
a fixed item per weekday. Wrapping the block in a `{% cache %}` with a
`ttl` of a day makes the intent explicit and saves the query.

## Performance and access

### Cache an expensive sidebar

Anything that queries several collections on every page is a candidate
for the `{% cache %}` tag. Tag it with the collections it reads and it
invalidates itself the moment one of them changes, so there is no stale
window to reason about:

```twig
{% cache 'sidebar' ttl=86400 tags=['blog', 'events', 'quotes'] shared=true %}
  {% include 'partials/recent-posts.twig' %}
  {% include 'partials/upcoming-events.twig' %}
  {% include 'partials/daily-quote.twig' %}
{% endcache %}
```

`shared=true` is right here because the sidebar is identical for
anonymous and logged-in visitors. Leave it off for anything that varies
by user, and caching is bypassed for logged-in requests automatically.
Vary the key when the fragment depends on the page,
e.g. `'sidebar:' ~ page.id`.

### Member-only blocks with a sign-in prompt

Gate a section of a page on a login, or on membership of an access group,
and show a prompt in its place otherwise. The login helper redirects back
to the current page afterwards by default:

```twig
{% if cms.auth.userLoggedIn('members') %}
  {% include 'partials/member-downloads.twig' %}
{% else %}
  <p class="gate">Members can download the full guide. <a href="{{ cms.auth.login('members') }}">Sign in</a></p>
{% endif %}

{% if cms.auth.userHasAccess('premium', 'members') %}
  {% include 'partials/premium-video.twig' %}
{% endif %}
```

This only hides markup. Files the block links to should live in a
protected collection or a password-guarded depot so the URL itself is not
enough. See [Access Groups](docs/auth/access-groups). Do not wrap a gated
block in `{% cache … shared=true %}`.

### Signed links that cannot be forged

A one-click unsubscribe or a personal download link has to carry an
identity the visitor cannot change. Sign the link: hash the id, an issue
time and a secret only the server knows, and send the hash along. The
receiving page recomputes the hash and compares. Keep the secret in a
private `settings` object so both templates read the same value, and make
sure that collection has no public read operation:

```twig
{# in the email template #}
{% set secret = cms.collection.object('settings', 'links').secret %}
{% set issued = 'now'|date('U') %}
{% set sig    = sha1(member.id ~ '|' ~ issued ~ '|' ~ secret) %}
<a href="https://{{ cms.domain }}/unsubscribe?id={{ member.id }}&t={{ issued }}&sig={{ sig }}">Unsubscribe</a>
```

```twig
{# on /unsubscribe #}
{% set secret   = cms.collection.object('settings', 'links').secret %}
{% set memberId = getData.id|default('') %}
{% set issued   = getData.t|default(0) %}
{% set expected = sha1(memberId ~ '|' ~ issued ~ '|' ~ secret) %}
{% set fresh    = ('now'|date('U') - issued) < 60 * 60 * 24 * 30 %}

{% if memberId and fresh and getData.sig == expected %}
  {# valid for 30 days: render the confirmation form for memberId #}
{% else %}
  <p>This link is invalid or has expired. Request a new one.</p>
{% endif %}
```

Changing `id` or `t` in the URL changes the expected hash, so the link
fails. The timestamp gives links an expiry. Do the actual write through a
form post so a link preview or a crawler cannot trigger it.

`encrypt` and `decrypt` are the tool when the value itself must be hidden
rather than merely protected from tampering, but `decrypt` raises an
error on a token that does not verify, and Twig cannot catch it. Prefer
the signature above anywhere the input comes from a URL.
