---
title: "Twig Collections Reference"
description: "Reference for the cms.collection namespace providing collection listing, object fetching, searching, URL generation, and navigation."
---

# cms.collection

The collection adapter provides access to collections and their objects, including listing, fetching, searching, URL generation, and navigation helpers.

## Listing Collections

### list()

Get all accessible collections (filtered by edition restrictions).

```twig
{% for col in cms.collection.list() %}
    <p>{{ col.name }} ({{ col.id }})</p>
{% endfor %}
```

### byCategory()

Get collections grouped by their category.

```twig
{% for category, collections in cms.collection.byCategory() %}
    <h3>{{ category }}</h3>
    {% for col in collections %}
        <p>{{ col.name }}</p>
    {% endfor %}
{% endfor %}
```

### get()

Get a single collection's metadata. Returns an empty array if the collection is inaccessible.

```twig
{% set blog = cms.collection.get('blog') %}
{% if blog %}
    <h1>{{ blog.name }}</h1>
    <p>{{ blog.description }}</p>
{% endif %}
```

### objectCount()

Get the number of objects in a collection using cached metadata. This is much more efficient than loading all objects and counting them.

```twig
<p>{{ cms.collection.objectCount('blog') }} blog posts</p>
<p>{{ cms.collection.objectCount('products') }} products</p>

{# Avoid this — loads entire index just to count #}
{# {{ cms.collection.objects('blog')|length }} #}
```

## Fetching Objects

### objects()

Get all objects from a collection.

```twig
{% for post in cms.collection.objects('blog') %}
    <article>
        <h2>{{ post.title }}</h2>
        <p>{{ post.excerpt }}</p>
    </article>
{% endfor %}
```

### object()

Get a single object by collection and ID. Returns an empty array if not found.

```twig
{% set post = cms.collection.object('blog', 'my-post') %}
{% if post %}
    <h1>{{ post.title }}</h1>
    {{ post.content|markdown }}
{% endif %}
```

### property()

Get all unique values for a specific property across a collection. Useful for building filter lists.

```twig
{# Get all categories used in blog posts #}
{% set categories = cms.collection.property('blog', 'category') %}
<select>
    {% for cat in categories %}
        <option>{{ cat }}</option>
    {% endfor %}
</select>

{# Get all tags #}
{% set tags = cms.collection.property('blog', 'tags') %}
```

## Search

### search()

Search a collection by query string against one or more properties. Properties are searched in priority order.

```twig
{# Search a single property #}
{% set results = cms.collection.search('blog', 'php tutorial', 'title') %}

{# Search multiple properties in priority order #}
{% set results = cms.collection.search('blog', query, ['title', 'content', 'tags']) %}

{% for item in results %}
    <article>
        <h3>{{ item.title }}</h3>
        <p>{{ item.excerpt }}</p>
    </article>
{% endfor %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `collection` | string | Collection to search |
| `query` | string | Search query string |
| `propertyPriorities` | string\|array | Property name or ordered array of properties to search |

## URL Generation

### objectUrl()

Get the URL for an object. Supports templated URL patterns with object data.

```twig
{# By ID #}
<a href="{{ cms.collection.objectUrl('blog', 'my-post') }}">Read More</a>

{# By object (recommended — avoids re-fetching for templated URLs) #}
{% set post = cms.collection.object('blog', 'my-post') %}
<a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a>
```

### prettyUrl()

Convert a URL path to a pretty URL (strips `.php` extension, adds trailing slash).

```twig
<a href="{{ cms.collection.prettyUrl('/blog/post.php') }}">Link</a>
{# Outputs: /blog/post/ #}

{# With domain prefix #}
<a href="{{ cms.collection.prettyUrl('/blog/post.php', true) }}">Full URL</a>
{# Outputs: https://example.com/blog/post/ #}
```

### canonicalObjectUrl()

Get the absolute canonical URL for an object (includes the full domain).

```twig
{% set post = cms.collection.object('blog', 'my-post') %}
<link rel="canonical" href="{{ cms.collection.canonicalObjectUrl('blog', post) }}">
```

### hasTemplateUrl()

Check if a collection uses a templated URL pattern (e.g., `/blog/{slug}/`).

```twig
{% if cms.collection.hasTemplateUrl('blog') %}
    <p>This collection uses pretty URLs</p>
{% endif %}
```

### urlTemplateFields()

Get the list of field names used in a collection's URL template.

```twig
{% set fields = cms.collection.urlTemplateFields('blog') %}
{# e.g., ['category', 'slug'] for /blog/{category}/{slug}/ #}
```

### validateUrlTemplateFields()

Validate that URL template fields are properly configured in the schema.

```twig
{% set validation = cms.collection.validateUrlTemplateFields('blog') %}
{% if validation.notIndexed|length > 0 %}
    <p>Warning: fields not indexed: {{ validation.notIndexed|join(', ') }}</p>
{% endif %}
{% if validation.notRequired|length > 0 %}
    <p>Warning: fields not required: {{ validation.notRequired|join(', ') }}</p>
{% endif %}
{% if validation.prettyUrlDisabled %}
    <p>Warning: pretty URLs are disabled</p>
{% endif %}
```

**Returns:** `array` with keys:
- `notIndexed` — field names not marked as indexed in the schema
- `notRequired` — field names not marked as required
- `prettyUrlDisabled` — whether pretty URLs are disabled for the collection

### objectUrlHasEmptySegments()

Check if an object's generated URL has empty segments due to missing template data.

```twig
{% set post = cms.collection.object('blog', 'my-post') %}
{% if cms.collection.objectUrlHasEmptySegments('blog', post) %}
    <p>Warning: this object has incomplete URL data</p>
{% endif %}
```

## Navigation

### redirectIfNotFound()

Redirect to the 404 page if the provided object is empty or null. Useful in detail page templates to handle missing objects.

```twig
{% set post = cms.collection.object('blog', id) %}
{{ cms.collection.redirectIfNotFound(post) }}

{# With custom redirect URL #}
{{ cms.collection.redirectIfNotFound(post, '/blog') }}

<h1>{{ post.title }}</h1>
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `object` | array\|string\|null | `[]` | Object to check |
| `redirectUrl` | string | `''` | Custom redirect URL (default: 404 page) |

### redirectToCanonicalUrl()

Redirect to the canonical URL if the current URL doesn't match. Supports multiple redirect methods.

```twig
{# Header redirect (default, recommended) #}
{{ cms.collection.redirectToCanonicalUrl('blog', post) }}

{# Meta refresh redirect #}
{{ cms.collection.redirectToCanonicalUrl('blog', post, 'meta') }}

{# JavaScript redirect #}
{{ cms.collection.redirectToCanonicalUrl('blog', post, 'js') }}

{# Custom HTTP status code #}
{{ cms.collection.redirectToCanonicalUrl('blog', post, 'header', 302) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `collectionId` | string | required | Collection identifier |
| `idOrObject` | string\|array | required | Object ID or full object data |
| `method` | string | `'header'` | Redirect method: `header`, `meta`, `js` |
| `httpStatus` | int | `301` | HTTP status code for header redirects |
