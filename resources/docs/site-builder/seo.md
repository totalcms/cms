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
- **Description.** Markup is stripped, whitespace collapsed to single spaces, and the result truncated to 160 characters on a word boundary with an ellipsis.
- **`og:type`.** Decided by the collection mapping alone. The SEO card's **Structured Data Type** does **not** change `og:type` — it only adds or removes the `Article` node in the JSON-LD. An object switched to Article on its card keeps `og:type: website` unless its collection is mapped to Article too.
- **Twitter card.** `summary_large_image` when an image resolved, `summary` when none did.
- **Robots.** The `<meta name="robots">` tag is emitted only when noindex or nofollow is on. No tag is the same as `index, follow`, and it is quieter.
- **Canonical.** Built from the Base URL setting (falling back to `https://{your domain}`). A Site Builder page whose route contains a `{placeholder}` gets no canonical — a route pattern is not an address. A collection object gets one only when the collection has its **URL** set.

## The SEO Card

Every Site Builder page has an **SEO** section on its edit form. It holds the per-record overrides:

| Field | What it does |
|---|---|
| **Title** | Replaces the derived title. Supports `${placeholder}` substitution — see [Placeholders in the Title](#placeholders-in-the-title) below. |
| **Description** | Replaces the meta description for this record. |
| **Social Image** | The share image. 1200×630 is the recommendation; Total CMS crops to that ratio. |
| **Canonical URL** | An absolute URL. Leave it empty to use this record's own URL — set it when the content is a duplicate of a page elsewhere. |
| **No Index** | Asks crawlers not to index this page. **Also removes it from the sitemaps.** |
| **No Follow** | Asks crawlers not to follow the links on this page. |
| **Structured Data Type** | `Collection default`, `Article`, or `None`. Opts one record into (or out of) the `Article` JSON-LD node, regardless of what its collection says. It does not affect `og:type`, which follows the collection mapping. |

Leave the card entirely empty and nothing is lost — every field falls through to the mapping and the site defaults.

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
| The Site Builder pages collection | `website` | `title` | `description` | `image` |
| Everything else | `website` | *(none)* | *(none)* | *(none)* |

The saved block is merged **over** the default one key at a time, so a blog collection that maps only its image keeps `article`, `title` and `summary` for the three keys it left alone. Clearing a field means "use the default", not "map nothing".

A collection with no mapping still gets a title, a canonical, Open Graph tags and the site's default description and image — the mapping only decides where the per-object title, description and image come from. Even with no title mapping the object's own `title` is used, so mapping one is for collections that name their headline something else.

## Site Settings

Everything site-wide lives on one record in the **Site SEO** collection — a reserved single-object collection with the id `seo-site`. It is a collection rather than a settings panel so the two images are real uploads rather than URLs you paste, and so the record is readable in Twig like any other object.

Open **Site SEO** in the collections sidebar and edit its one record. If it is not there, run **Project Setup → Setup Default Collections**; that provisions it alongside the other reserved collections and is safe to run on an existing site — it creates what is missing and leaves the rest alone.

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

## Sitemaps and `noindex`

A page or object whose SEO card has **No Index** on is dropped from the sitemap it would otherwise appear in — both `/sitemap/-pages` for Site Builder pages and `/sitemap/{collection}` for objects. A crawler receiving `noindex` in the head and the same URL in the sitemap is being told two different things; Total CMS only ever tells it one.

The **Include in Sitemap** toggle is unchanged and independent. Off keeps a record out of the sitemap without asking anyone not to index it; **No Index** does both.

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
- **More schema.org types** — Product, Event, FAQ, Recipe, LocalBusiness and the rest.
- **404 and redirect management** beyond the Site Builder page `status` and `redirectTo` fields.

The last three are the remit of the **SEO Pro** extension planned for 3.6.

## See Also

- [Sitemaps](docs/collections/sitemap-builder)
- [Builder Twig Reference](docs/site-builder/twig)
- [Starter Templates](docs/site-builder/starters)
