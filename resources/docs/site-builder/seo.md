---
title: "SEO"
description: "One Twig call emits the title, description, canonical, robots, Open Graph, Twitter and JSON-LD for any Site Builder page or collection object."
since: "3.5.3"
related:
  - collections/sitemap-builder
  - site-builder/twig
  - site-builder/starters
---

# SEO

Total CMS writes the `<head>` for you. One call in your layout emits the title, meta description, canonical link, robots directives, Open Graph and Twitter card tags, search-engine verification tags and a JSON-LD `@graph` — for a Site Builder page, for a collection object, or for the site on its own.

Nothing is generated ahead of time and there is no build step. Every value is resolved at render time from three places, in order: the **SEO card** on the record, the **collection's field mapping**, then the **site defaults** on the [Site SEO record](#site-settings).

## The One-Liner

Put this in the `<head>` of your layout:

```twig
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{ cms.seo.head(page|default(null)) }}
    {{ cms.assetsHead() }}
</head>
```

That is the whole integration. `page` is the Site Builder page record the router already put in scope; `|default(null)` keeps the call working on a template rendered outside the page router, where it falls back to the site defaults.

For a page rendered from a starter, the output looks like this:

```html
<title>About | Bistro</title>
<meta name="description" content="Where we came from and who cooks the food.">
<link rel="canonical" href="https://example.com/about">
<meta property="og:type" content="website">
<meta property="og:title" content="About">
<meta property="og:description" content="Where we came from and who cooks the food.">
<meta property="og:url" content="https://example.com/about">
<meta property="og:site_name" content="Bistro">
<meta property="og:image" content="https://example.com/imageworks/...">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@bistro">
<meta name="google-site-verification" content="g123">
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[…]}</script>
```

### No `|raw`

`cms.seo.head()` returns markup, not a string — print it with plain `{{ }}`. Adding `|raw` is unnecessary, and every value inside it is escaped by Total CMS before it reaches the page, exactly like [`cms.assetsHead()`](docs/site-builder/frontend).

### Remove Your Own Tags

`head()` emits `<title>` and `<meta name="description">`. If your layout already has its own, delete them — otherwise the page ships two of each and crawlers pick whichever they like.

The four bundled [starter templates](docs/site-builder/starters) already do this. Their layouts carry:

```twig
{% block seo %}{{ cms.seo.head(page|default(null)) }}{% endblock %}
```

in place of the old `{% block title %}` / `{% block description %}` pair, so a page template can override the whole block when it renders something other than the page record — see [Collection Objects](#collection-objects) below.

## What You Get For Free

Every value falls through the same three-step chain. The first non-empty one wins.

| Value | 1. SEO card on the record | 2. Collection mapping | 3. Site default |
|---|---|---|---|
| **Title** | `seo.title` | the mapped title property, then the record's own `title` | Site Name |
| **Description** | `seo.description` | the mapped property, stripped to plain text | Default Description |
| **Social image** | `seo.image` | the mapped image property | Default Social Image |
| **Canonical** | `seo.canonical` | the record's own absolute URL | *(omitted)* |
| **Robots** | `seo.noindex` / `seo.nofollow` | — | *(omitted)* |
| **`og:type`** | — | `article` when the collection's type is Article | `website` |

A few rules the chain applies on top:

- **Title template.** The resolved title is run through the site's title template, `{title} | {site}` by default. A record with no title collapses to the site name; a site with no name leaves the raw title. No dangling separator either way.
- **Description.** A mapped property is as often markdown as it is HTML, so both are flattened: tags are stripped, entities decoded, then the markdown markers are removed — a link or an image keeps its text and loses its brackets and URL, `**bold**`, `_italic_` and `` `code` `` lose their wrappers, and a heading marker, a list bullet or a `>` at the start of a line goes. Ordinary prose comes back untouched: a lone `!`, a `$5 * 3` or a `my_var` is a sentence, not markdown, and is left alone. Whatever survives is then collapsed to single spaces. That cleanup and the 160-character cap are for mapped properties only, which may hold a whole summary or article body. A description written on the card, or the site default, is emitted exactly as written, however long: search engines index the whole tag even though they display only the first 150 characters or so.
- **Social title.** `og:title` always prints: the card's **Social Title** when it has one, otherwise the record's own title before the site title template is applied — the bare `About`, not `About | Bistro` — falling back to the site name on a page with no record behind it. `twitter:title`, `twitter:description` and `twitter:image` are declared explicitly with the same values as their `og:` counterparts — most scrapers fall back to Open Graph, but not all of them. There is no collection mapping for the Social Title — it is a per-record override or nothing.
- **Image alt.** When the winning image carries alt text, it is emitted as `og:image:alt` and `twitter:image:alt`. The alt is read from whichever image actually won — the card's, the mapped property's, or the Site SEO default image's — so it always describes the picture on the card. An image with no alt simply omits both tags.
- **`og:type`.** Decided by the collection mapping alone. The SEO card's **Structured Data Type** does **not** change `og:type` — it only adds or removes the `Article` node in the JSON-LD. An object switched to Article on its card keeps `og:type: website` unless its collection is mapped to Article too.
- **Twitter card.** `summary_large_image` when an image resolved, `summary` when none did.
- **Robots.** The `<meta name="robots">` tag is emitted only when noindex or nofollow is on. No tag is the same as `index, follow`, and it is quieter.
- **Canonical.** Built from the Base URL setting (falling back to `https://{your domain}`). A Site Builder page whose route contains a `{placeholder}` gets no canonical — a route pattern is not an address. A collection object gets one only when the collection has its **URL** set. A record with **No Index** on gets none either — see [Sitemaps and `noindex`](#sitemaps-and-noindex).

## The SEO Card

Every Site Builder page has an **SEO** section on its edit form. It holds the per-record overrides:

| Field | What it does |
|---|---|
| **Title** | Replaces the derived title. Supports `${placeholder}` substitution — see [Placeholders in the Title](#placeholders-in-the-title) below. |
| **Social Title** | A shorter, punchier title for share cards. It replaces `og:title` and `twitter:title` only — `<title>` is untouched, and it is never run through the site title template. Leave it empty and the share cards keep using the page title, exactly as before. |
| **Description** | Replaces the meta description for this record. |
| **Social Image** | The share image. 1200×630 is the recommendation; Total CMS crops to that ratio. |
| **Canonical URL** | An absolute URL. Leave it empty to use this record's own URL — set it when the content is a duplicate of a page elsewhere. |
| **No Index** | Asks crawlers not to index this page. **Also removes it from the sitemaps.** |
| **No Follow** | Asks crawlers not to follow the links on this page. |
| **Structured Data Type** | `Collection default`, `Article`, or `None`. Opts one record into (or out of) the `Article` JSON-LD node, regardless of what its collection says. It does not affect `og:type`, which follows the collection mapping. |

Leave the card entirely empty and nothing is lost — every field falls through to the mapping and the site defaults.

### Builder Pages

A Site Builder page used to carry its own **Meta Description** and **Page Image** at the top of the edit form, next to the title. Both are gone from the page: the SEO card is now the only place a page's description and share image live. `title` stays where it was — it names the page in the admin and the navigation, not only in the head.

One card instead of two homes for the same value, and everything on this page applies to a builder page as it does to any other record: `${placeholder}` titles, **Social Title**, **No Index**, **Structured Data Type**.

**Your pages are migrated for you.** On the first request to your site after updating — a Site Builder page counts, you do not have to open the admin — a one-time migration walks the pages collection and moves the old values onto the card: an existing page description into **Description** and an existing page image into **Social Image**, files and all (`builder-pages/{id}/image/` becomes `builder-pages/{id}/seo/image/`). Each migrated page is re-saved silently, then the pages index is rebuilt once — one rebuild for the whole batch instead of one per page. Because the saves are silent, nothing downstream is notified: if your search provider indexes `seo.description`, run `tcms search:reindex` afterwards. It runs once, it is safe to run again, and it never overwrites: a page whose card was already filled in keeps the card's value and is left alone entirely — its old top-level value stays in the stored JSON, and its old image files stay in `builder-pages/{id}/image/`, until the next time that page is saved. Neither is ever read again; the schema no longer declares those properties, so nothing can reach them.

**Templates reading the old properties need one rename.** `page.description` and `page.image` no longer resolve — the schema does not declare them, so the page record does not carry them:

```twig
{# before #}
{{ page.description }}
{{ page.image.alt }}

{# after #}
{{ page.seo.description }}
{{ page.seo.image.alt }}
```

A hero image is the case that needs a little more than a rename: the image is nested inside a card now, so `imagePath()` wants the dotted path — `{collection: 'builder-pages', property: 'seo.image'}`. See [Page Description and Image](docs/site-builder/overview) for the full call.

If your layout only calls `cms.seo.head(page)` there is nothing to rename at all — the head was already reading through the card. The bundled [starter templates](docs/site-builder/starters) are already updated.

### Placeholders in the Title

The card's **Title** may compose a title out of the record rather than restate it. Anything in `${...}` is replaced with that property's value:

```
${name} — ${city}
```

on a record with `name: "Tony's"` and `city: "Austin"` gives `Tony's — Austin`, which then goes through the site title template like any other title: `Tony's — Austin | Bistro`.

- Any property of the record works — `${title}`, `${author}` — and dot paths reach inside a card or a deck item: `${hero.headline}`.
- `${site}` is the site name. It wins even on a record that has its own property called `site`.
- A property that holds something other than text — an image, a card, a deck — resolves to nothing rather than the literal word `Array`.
- A title whose placeholders **all** come back empty falls through to the rest of the chain instead of emitting a half-built title with the punctuation still in it.

### Adding the Card to Your Own Schema

The card ships on `builder-page` only. Any custom schema can opt in by adding a `seo` card property that references the reserved `seo` schema:

```json
"seo": {
    "$ref"      : "https://www.totalcms.co/schemas/properties/card.json",
    "field"     : "card",
    "schemaref" : "https://www.totalcms.co/schemas/seo.json",
    "label"     : "SEO"
}
```

Add `seo` to the schema's `formgrid` so it appears on the form — a section of its own reads best:

```
---SEO---
seo seo
```

A card has to span the whole row. Give it half a row (`seo .`) and its own inner grid collapses — both schemas that ship the card use `seo seo`.

And add `"seo"` to the schema's `index` array:

```json
"index": ["id", "title", "seo", "updated", "created"]
```

> **The index entry is not optional if you want noindex honoured in sitemaps.** The sitemap builders read the collection index, not the object files. Without `seo` in `index`, the card still drives the `<head>`, but a noindexed object stays in `/sitemap/{collection}`.

## Collection Mapping

Most collections already carry a headline, a description and an image under names of their own — `headline`, `summary`, `excerpt`, `hero`, `photo`. Rather than copy those values into an SEO card on every object, tell the collection which properties to read.

Open **Collections → your collection → Settings** and fill in the **SEO** section:

| Setting | What it does |
|---|---|
| **Structured Data Type** | `Schema default (Article for blog and feed schemas, Website otherwise)`, `Website` or `Article`. Article adds an `Article` node to the JSON-LD for every object in the collection. Leave it on the schema default to keep the schema's own — `Article` for a blog or feed collection, `Website` everywhere else — or pick `Website` to opt a blog collection out. |
| **Title Property** | Which property supplies the title when an object has no SEO title. Leave it empty to use the object's own `title`. |
| **Description Property** | Which property supplies the meta description when an object has no SEO description. |
| **Image Property** | Which image property supplies the social image. |

Saving writes the mapping into the collection's `.meta.json`:

```json
"seo": {
    "type": "article",
    "title": "title",
    "description": "summary",
    "image": "image"
}
```

### Defaults

You do not have to configure anything for a blog — or for any other schema Total CMS ships with an obvious headline, description and image:

| Collection | Type | Title | Description | Image |
|---|---|---|---|---|
| Any collection using the `blog` schema | `article` | `title` | `summary` | `image` |
| Any collection using the `blog-legacy` schema | `article` | `title` | `summary` | `image` |
| Any collection using the `feed` schema | `article` | `title` | `content` | `image` |
| Any collection using the `podcast-episode` schema | `website` | `title` | `summary` | `art` |
| The Site Builder pages collection | `website` | `title` | *(none — the card)* | *(none — the card)* |
| Everything else | `website` | *(none)* | *(none)* | *(none)* |

The saved block is merged **over** the default one key at a time, so a blog collection that maps only its image keeps `article`, `title` and `summary` for the three keys it left alone. Clearing a field means "use the default", not "map nothing".

A collection with no mapping still gets a title, a canonical, Open Graph tags and the site's default description and image — the mapping only decides where the per-object title, description and image come from. Even with no title mapping the object's own `title` is used, so mapping one is for collections that name their headline something else.

Site Builder pages are the one row with nothing to map for two of the three: a page has a `title` and an [SEO card](#builder-pages), and that is where its description and share image live. There is no second property for the mapping to point at.

## Site Settings

Everything site-wide lives on one record in the **Site SEO** collection — a reserved single-object collection with the id `seo-site`. It is a collection rather than a settings panel so the two images are real uploads rather than URLs you paste, and so the record is readable in Twig like any other object.

Open **Site SEO** in the collections sidebar and edit its one record. If it is not there, provision just that collection from the command line:

```bash
tcms collection:create seo-site
```

`seo-site` is a reserved id, so no `--schema` is needed — the collection is created with its shipped name and singleton flag, the same result **Project Setup → Setup Default Collections** gives but for that one collection instead of every default. Either route is safe on an existing site: both create what is missing and leave the rest alone. See the [CLI reference](docs/extensions/cli) for the rest of `collection:create`.

| Setting | Notes |
|---|---|
| **Site Name** | Used in titles, `og:site_name` and the WebSite / Organization JSON-LD. Leave it empty and Total CMS falls back to the General settings site name, then your domain. |
| **Base URL** | The absolute origin for canonical URLs and JSON-LD ids, e.g. `https://example.com`. Defaults to this site's domain, and `https://` is assumed if you omit the scheme. The sitemaps use this same value. |
| **Title Template** | `{title}` and `{site}` are replaced. Default: `{title} \| {site}` |
| **Title Separator** | Replaces the literal `\|` in the template, so a theme can use `–` or `·` without rewriting the template. |
| **Default Description** | Used when a record has no description of its own. |
| **Default Social Image** | An image **upload**, not a URL. The fallback share image, served through ImageWorks at 1200×630 and emitted as an absolute URL. |
| **Twitter / X Handle** | With or without the `@`. Emitted as `twitter:site`. |
| **Organization Name** | The publishing entity in the JSON-LD. Falls back to the site name. |
| **Organization Logo** | An image **upload** too. Any aspect ratio, at least 112×112 — it is never cropped or upscaled, only bounded, and served through ImageWorks at up to 600px wide. |
| **Social Profiles** | One absolute URL per line — X, Instagram, LinkedIn, GitHub. Emitted as `Organization.sameAs`. |
| **Google / Bing / Pinterest Verification** | The `content` value each service gives you, not the whole tag. They become `google-site-verification`, `msvalidate.01` and `p:domain_verify`. |
| **Emit JSON-LD** | Off suppresses the `<script type="application/ld+json">` block entirely. |
| **Emit Open Graph and Twitter tags** | Off suppresses both sets of social tags. |

A site that has never opened this record still gets a full head: every value falls back to its default, the site name to the General settings name and then the domain, and the base URL to `https://{your domain}`. Nothing has to be filled in for `cms.seo.head()` to work.

The properties above are the ones the SEO output reads, and they are the whole list. `seo-site` is a *reserved* schema, so you cannot save over it — but you can present it differently, and you can add to it.

To change how the shipped fields look, open **Collections → Site SEO → Settings → Schema Overrides** to relabel a field, rewrite its help text or swap its field type for this site, or **Object Specific Overrides** to do the same for the one record. Both override the schema's fields; neither adds new ones.

### Adding Your Own Site-Wide Fields

Site-wide values of your own — a tagline, a phone number, a footer blurb — can live on the same record, through schema inheritance. Three steps:

> **Custom schemas are a Pro edition feature.** Creating one needs Pro or above; on a lower edition the schema routes are refused and this recipe is not available. Everything else on this page works on every edition.

**1. Create a schema that inherits `seo-site`.** Give it your own id and only the properties you are adding; the reserved schema's fields come along:

```json
{
    "id": "myseo",
    "type": "object",
    "inheritFrom": ["seo-site"],
    "formgrid": "siteName baseUrl\ntitleTemplate titleSeparator\ndefaultDescription defaultDescription\ndefaultImage twitterHandle\n---Organization---\norganizationName organizationLogo\nsameAs sameAs\n---Verification---\ngoogleVerification bingVerification\npinterestVerification .\n---Output---\nemitJsonLd emitSocial\n---Site---\ntagline .",
    "properties": {
        "tagline": {
            "type": "string",
            "field": "text",
            "label": "Tagline"
        }
    }
}
```

**`formgrid` is not inherited.** Properties are, but the layout is not — the child's own `formgrid` is kept as-is, and a schema without one renders every field as a flat single-column stack. Copy `seo-site.json`'s `formgrid` verbatim (above) and append rows for your new fields, or the Site SEO form loses its sections.

**2. Point the `seo-site` collection at it.** On a new site, create the collection yourself: id `seo-site`, schema `myseo`, **Singleton** on.

On a site that already has the collection, the admin cannot switch it: the **Schema** select is disabled when you edit an existing collection. Do one of these instead:

- Delete the `seo-site` collection and recreate it with schema `myseo`, then re-enter the record. Export the record first (**Collections → Site SEO → Export**) if you would rather not retype it.
- Or change the schema without the form: `PATCH /api/collections/seo-site` with `{"schema": "myseo"}`, or edit `schema` in `tcms-data/seo-site/.meta.json` directly. The objects keep their values; the extra properties simply start rendering.

Either way the collection id stays `seo-site` — that is what the SEO output looks for. **Project Setup → Setup Default Collections** only creates the collection when it is missing, so it will leave yours alone.

**3. Use the new fields.** They appear on the Site SEO form alongside the shipped ones, and the record reads in Twig like any other object:

```twig
{{ cms.collection.object('seo-site', 'seo-site').tagline }}
```

Core keeps reading only the fields it knows, so `cms.seo.head()` behaves exactly as before — your additions ride along on the same record.

### Verification Tags

Paste only the token. Google hands you a whole tag:

```html
<meta name="google-site-verification" content="AbC123_xyz" />
```

`AbC123_xyz` is what goes in the field. The tag is emitted on every page that calls `cms.seo.head()` or `cms.seo.meta()`.

## Structured Data

With **Emit JSON-LD** on, `head()` writes one `<script type="application/ld+json">` containing a single `@graph`. Nodes cross-reference each other by `@id`, which is what search engines expect over a pile of disconnected blocks.

| Node | `@id` | When |
|---|---|---|
| `Organization` | `{base}/#organization` | Whenever an organization name (or site name) exists |
| `WebSite` | `{base}/#website` | Always |
| `WebPage` | `{url}#webpage` | When the page or object has a resolvable URL |
| `BreadcrumbList` | `{url}#breadcrumb` | Alongside a WebPage: Home → collection → this page |
| `Article` | `{url}#article` | Objects in an Article-mapped collection, or with the card set to Article |

An `Article` node carries the headline, description, image, `datePublished` and `dateModified` from the object, an `author` Person built from the object's `author` value, and a `publisher` reference to the Organization. Set the card's **Structured Data Type** to `None` to leave one object out.

The JSON is encoded so that a `</script>` inside any value cannot break out of the tag.

Validate what a page emits at [validator.schema.org](https://validator.schema.org/) — paste the page URL, or the JSON-LD block itself.

### Adding Your Own Structured Data

Core emits the five nodes above and stops there. A page that needs an FAQ, a product, an event or a software listing can hand its own nodes to `head()` — they join the same `@graph` rather than arriving in a second `<script>` block that describes an unrelated island:

```twig
{% set seo = cms.seo.data(page) %}

{% set faq = {
    '@type'     : 'FAQPage',
    '@id'       : seo.canonical ~ '#faq',
    'publisher' : { '@id': seo.site.baseUrl ~ '/#organization' },
    'mainEntity': [
        {
            '@type'         : 'Question',
            'name'          : 'Is there a build step?',
            'acceptedAnswer': { '@type': 'Answer', 'text': 'No. Every value is resolved when the page renders.' }
        },
        {
            '@type'         : 'Question',
            'name'          : 'Can I host it myself?',
            'acceptedAnswer': { '@type': 'Answer', 'text': 'Yes — any PHP 8.2 host with a writable data directory.' }
        }
    ]
} %}

{{ cms.seo.head(page, {jsonld: [faq]}) }}
```

`cms.seo.jsonld()` takes the same option, for a layout that places the script itself.

A few rules:

- **Your nodes land after the core ones**, and the first node for any `@id` wins. That is what makes `{ '@id': seo.site.baseUrl ~ '/#organization' }` above a *reference* — core's Organization node is already in the graph, so yours links to the real thing instead of replacing it with a stub. The three ids worth referencing are `{base}/#organization`, `{base}/#website` and `{url}#webpage`; [`cms.seo.data()`](#reusing-the-values) gives you both halves to build them from.
- **A node that repeats a core `@id` is dropped**, for the same reason. To describe an entity core already describes, give it an id of its own.
- **Every entry must be a hash, not a list.** `{jsonld: [faq]}` is a list of one hash — the outer `[...]` holds the nodes, each node is a `{...}`. An entry that is not a hash is ignored rather than written: a `[...]` where a node belongs (the easy mistake of wrapping one node in an extra pair of brackets) is dropped, and so is anything that is not an array at all.
- **Nothing is interpolated.** Your nodes go through the same `json_encode` as core's, with the same `</script>` protection, so a value containing markup or a quote cannot break out of the tag. Write plain Twig values; do not pre-encode them.
- **Off means off.** With **Emit JSON-LD** switched off on the Site SEO record, no script is written at all — your nodes included.

## Collection Objects

A Site Builder route like `/blog/{id}` renders one blog post, and the head should describe the post, not the page record that routes to it. Pass the object instead, and name its collection:

```twig
{% extends 'layouts/default.twig' %}

{% set post = cms.collection.object('blog', params.id) %}

{% block seo %}{{ post ? cms.seo.head(post, {collection: 'blog'}) : cms.seo.head(page|default(null)) }}{% endblock %}

{% block content %}
    <h1>{{ post.title }}</h1>
    {{ post.content|markdown }}
{% endblock %}
```

That single change is what gives the post its own title, its own canonical URL, its own share image and its Article markup. The fallback keeps a missing post on the page's own metadata rather than a blank head.

Always pass `{collection: 'blog'}`. An object array does not carry the name of the collection it came from, and without it Total CMS cannot read the collection's mapping, resolve the object's URL or build the image.

> **The reserved `blog` collection ships with no URL.** Set **URL** on the collection (e.g. `/blog/{id}`) to match the route your page uses, or objects in it get no canonical, no `og:url` and no Article node — all three are anchored to the object's address. See [Collection Settings](docs/collections/settings#url).

The same call works for any collection — products, events, team members — once its URL is set.

## Granular Methods

`head()` is the whole block. When you need to place pieces yourself, the same resolution is available one slice at a time. Every method takes the same two arguments as `head()`.

| Method | Emits |
|---|---|
| `cms.seo.head(subject, options)` | Everything below, in document order |
| `cms.seo.title(subject, options)` | `<title>` |
| `cms.seo.meta(subject, options)` | `description`, `robots` and the verification tags |
| `cms.seo.og(subject, options)` | The Open Graph and Twitter card tags |
| `cms.seo.canonical(subject, options)` | `<link rel="canonical">` |
| `cms.seo.jsonld(subject, options)` | The `<script type="application/ld+json">` block |
| `cms.seo.data(subject, options)` | Nothing — it returns the resolved values as an array instead of markup. See [Reusing the Values](#reusing-the-values) |

```twig
<head>
    {{ cms.seo.title(post, {collection: 'blog'}) }}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{ cms.seo.meta(post, {collection: 'blog'}) }}
    {{ cms.seo.canonical(post, {collection: 'blog'}) }}
    {{ cms.seo.og(post, {collection: 'blog'}) }}
    {{ cms.seo.jsonld(post, {collection: 'blog'}) }}
</head>
```

Calling `cms.seo.head()` with no arguments at all is legitimate — a 404 template, a search results page, anything with no record behind it. You get the site's title, default description and the WebSite / Organization JSON-LD.

## Reusing the Values

Sometimes the value is wanted in the body rather than the head — a share button that needs the title, a preview card that needs the image, a script tag of your own that needs the description. `cms.seo.data()` runs the same resolution and hands back a plain array instead of markup:

```twig
{% set seo = cms.seo.data(page) %}

<img src="{{ seo.ogImage|e }}" alt="{{ seo.ogImageAlt|e }}">
<a href="https://x.com/intent/post?text={{ seo.socialTitle|default(seo.rawTitle)|url_encode }}">Share</a>
```

Everything `head()` prints is in there, under its own name:

| Key | What it holds |
|---|---|
| `title` | The final `<title>`, after the site title template |
| `rawTitle` | The record's title before the template — the bare `About`, not `About \| Bistro` |
| `socialTitle` | The card's **Social Title**, or `''` |
| `description` | The resolved description (a mapped property is cleaned and capped at 160 characters; card and site-default text is verbatim) |
| `canonical` | The canonical URL, even on a noindex record where the tag is not printed |
| `robots` | `''`, `noindex`, `nofollow` or `noindex, nofollow` |
| `noindex` | The boolean on its own |
| `ogType` | `website` or `article` |
| `ogImage` / `ogImageAlt` | The absolute image URL and its alt, each `''` when there is none |
| `twitterCard` | `summary_large_image` or `summary` |
| `siteName`, `twitterHandle` | As resolved for the tags |
| `verification` | `{google, bing, pinterest}` |
| `site` | The Site SEO record's own values: `name`, `baseUrl`, `defaultImage`, `defaultImageAlt`, `organizationName`, `organizationLogo`, `sameAs` |

**Escape them yourself.** These are plain, unescaped strings — not the `Markup` the other `cms.seo.*` methods return — and Total CMS runs Twig with autoescaping **off**, so a bare `{{ seo.ogImageAlt }}` puts whatever an operator typed into your page verbatim. Add `|e` wherever a value lands in markup, as in the example above. That difference is the whole point of the split: `cms.seo.head()` escapes every value inside its own `{% autoescape 'html' %}` block because it is building the tags itself, and `data()` cannot — it does not know whether you are about to drop the string into an attribute, a URL, or a JSON literal, each of which needs a different escape (`|e`, `|url_encode`, `|json_encode`).

The common use is a JSON-LD node of your own that should say the same thing as the head rather than a hand-maintained copy of it:

```twig
{% set seo = cms.seo.data(page) %}
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    "name": {{ seo.site.name|json_encode|raw }},
    "description": {{ seo.description|json_encode|raw }},
    "image": {{ seo.ogImage|json_encode|raw }},
    "url": {{ seo.canonical|json_encode|raw }},
    "applicationCategory": "DeveloperApplication",
    "operatingSystem": "Any"
}
</script>
```

`|json_encode` is what makes each value safe in that position — it quotes and escapes the string, so a description containing a quote or a backslash cannot break the JSON. Better still, hand the node to `head()` as [`options.jsonld`](#adding-your-own-structured-data) and let it join the core `@graph`, which is what a search engine would rather read.

## Sitemaps and `noindex`

A page or object whose SEO card has **No Index** on is dropped from the sitemap it would otherwise appear in — both `/sitemap/-pages` for Site Builder pages and `/sitemap/{collection}` for objects. A crawler receiving `noindex` in the head and the same URL in the sitemap is being told two different things; Total CMS only ever tells it one.

The **Include in Sitemap** toggle is unchanged and independent. Off keeps a record out of the sitemap without asking anyone not to index it; **No Index** does both.

**A noindexed page also gets no `<link rel="canonical">`.** Declaring a canonical URL is telling a crawler "this is the address to index"; asking not to be indexed at the same time is a mixed signal, and the two together are a documented way to have the directive ignored. So `head()` and `cms.seo.canonical()` both drop the tag when **No Index** is on — including the case where the card names an explicit **Canonical URL**. `og:url` still prints: it is an identity for a share card, not an instruction to a search engine.

For collections other than the Site Builder pages, remember the [index requirement](#adding-the-card-to-your-own-schema) — the sitemap builder reads the collection index, so `seo` must be in the schema's `index` array. See [Sitemaps](docs/collections/sitemap-builder).

### robots.txt

`robots.txt` is not managed for you. Add a Site Builder page routed at `/robots.txt` and point crawlers at the sitemap index:

```
User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
```

## What Is Not Included

Core SEO covers the markup every site needs. It deliberately stops short of:

- **A managed `robots.txt`** — it stays a Site Builder page you control, as above.
- **hreflang and localized SEO** — a site serving several languages has to emit its own alternate links. Native internationalization is planned.
- **Search Console / Bing Webmaster API integration** — verification tags are emitted; nothing is submitted or read back.
- **Analysis and scoring** — no readability grade, keyword density, or per-page SEO report.
- **More schema.org types** — Product, Event, FAQ, Recipe, LocalBusiness and the rest. Core generates none of them from your fields, though a template can write one itself and add it to the graph — see [Adding Your Own Structured Data](#adding-your-own-structured-data).
- **404 and redirect management** beyond the Site Builder page `status` and `redirectTo` fields.

The last three are the remit of the **SEO Pro** extension planned for 3.6.

## See Also

- [Sitemaps](docs/collections/sitemap-builder)
- [Builder Twig Reference](docs/site-builder/twig)
- [Starter Templates](docs/site-builder/starters)
