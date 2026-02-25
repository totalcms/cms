# Object Linking

This document covers how to create and manage URLs for collection objects in Total CMS, including the powerful URL templating system for creating SEO-friendly, human-readable URLs.

## Overview

Total CMS provides flexible URL generation for collection objects:

- **Simple URLs**: `/blog/my-post-id`
- **Query String URLs**: `/blog/?id=my-post-id`
- **Templated URLs**: `/blog/technology/2025/my-post-id`

## Collection URL Settings

Each collection has URL-related settings configured in the collection form:

| Setting | Description |
|---------|-------------|
| `url` | Base URL path for the collection (e.g., `/blog/`) |
| `prettyUrl` | Enable pretty URLs (`/blog/my-post`) vs query strings (`/blog/?id=my-post`) |

## URL Templating

URL templates allow you to include object field values in the URL path, creating SEO-friendly, descriptive URLs.

### Basic Syntax

Use double curly braces to insert field values:

```
/blog/{{ category }}/{{ id }}
```

For an object with `category: "technology"` and `id: "my-post"`, this generates:

```
/blog/technology/my-post
```

### Template Examples

```
# Include category in URL
/blog/{{ category }}/{{ id }}
# Result: /blog/technology/my-post

# Multi-level hierarchy
/campsites/{{ region }}/{{ county }}/{{ id }}
# Result: /campsites/pacific-northwest/king-county/pine-grove-camp

# Year-based blog URLs
/news/{{ year }}/{{ month }}/{{ id }}
# Result: /news/2025/12/holiday-announcement
```

### Auto-Appending ID

If your template doesn't include `{{ id }}`, Total CMS automatically appends it:

```
# Template configured as:
/blog/{{ category }}

# Automatically becomes:
/blog/{{ category }}/{{ id }}
```

### Available Filters

Filters transform field values before inserting them into the URL. Chain multiple filters with the pipe (`|`) character:

| Filter | Description | Example |
|--------|-------------|---------|
| `lower` | Convert to lowercase | `{{ category \| lower }}` |
| `upper` | Convert to uppercase | `{{ category \| upper }}` |
| `trim` | Remove leading/trailing whitespace | `{{ category \| trim }}` |
| `raw` | Skip automatic slug conversion | `{{ category \| raw }}` |

#### Filter Examples

```twig
# Force lowercase (values are auto-slugified anyway)
/blog/{{ category | lower }}/{{ id }}

# Chain multiple filters
/blog/{{ category | trim | lower }}/{{ id }}

# Preserve exact value (skip slugify)
/files/{{ path | raw }}
```

### Automatic Slugification

All template values are automatically converted to URL-safe slugs unless the `raw` filter is used:

- Converts to lowercase
- Replaces spaces and special characters with hyphens
- Removes leading/trailing hyphens

```
Input:  "Technology & Science"
Output: "technology-science"

Input:  "My Great Post!"
Output: "my-great-post"
```

### Pretty URL Requirement

URL templates only work when `prettyUrl` is enabled for the collection. If `prettyUrl` is disabled, the template syntax is ignored and query string format is used:

```
# With prettyUrl: true
Template: /blog/{{ category }}/{{ id }}
Result:   /blog/technology/my-post

# With prettyUrl: false
Template: /blog/{{ category }}/{{ id }}
Result:   /blog/{{ category }}/{{ id }}?id=my-post
```

## Twig Functions

### cms.collection.objectUrl()

Get the URL for an object. Supports both simple ID lookup and full object data.

```twig
{# Using object ID #}
<a href="{{ cms.collection.objectUrl('blog', 'my-post') }}">Read More</a>

{# Using full object (more efficient for templated URLs) #}
{% for post in cms.collection.objects('blog') %}
    <a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a>
{% endfor %}
```

**Parameters:**
- `collectionId` (string): The collection ID
- `idOrObject` (string|array): Object ID or full object array

**Returns:** The relative URL path

### cms.collection.canonicalObjectUrl()

Get the absolute canonical URL for an object, including scheme and domain.

```twig
{# Generate canonical URL for SEO #}
<link rel="canonical" href="{{ cms.collection.canonicalObjectUrl('blog', post) }}">

{# Use in Open Graph tags #}
<meta property="og:url" content="{{ cms.collection.canonicalObjectUrl('blog', post) }}">
```

**Parameters:**
- `collectionId` (string): The collection ID
- `idOrObject` (string|array): Object ID or full object array

**Returns:** Absolute URL (e.g., `https://example.com/blog/technology/my-post`)

### cms.collection.redirectToCanonicalUrl()

Redirect visitors to the canonical URL if they accessed the page via a non-canonical path. Essential for SEO when using templated URLs.

```twig
{# At the top of your object template #}
{{ cms.collection.redirectToCanonicalUrl('blog', post) }}

{# With custom redirect method #}
{{ cms.collection.redirectToCanonicalUrl('blog', post, 'meta') }}

{# With custom HTTP status (302 temporary instead of 301 permanent) #}
{{ cms.collection.redirectToCanonicalUrl('blog', post, 'header', 302) }}
```

**Parameters:**
- `collectionId` (string): The collection ID
- `idOrObject` (string|array): Object ID or full object array
- `method` (string, optional): Redirect method - `'header'` (default), `'meta'`, `'js'`, or `'both'`
- `httpStatus` (int, optional): HTTP status code for header redirects - `301` (default) or `302`

**Redirect Methods:**
| Method | Description |
|--------|-------------|
| `header` | HTTP 301/302 redirect (best for SEO, must be before output) |
| `meta` | HTML meta refresh tag |
| `js` | JavaScript redirect |
| `both` | Both meta refresh and JavaScript |

**Returns:** HTML string for non-header methods, or exits immediately for header redirects

### Use Case: Old URL Compatibility

When users access an old URL format, redirect them to the canonical URL:

```twig
{# Someone visits /blog/?id=my-post but canonical is /blog/technology/my-post #}
{{ cms.collection.redirectToCanonicalUrl('blog', post) }}

{# Query parameters (like UTM tracking) are preserved on redirect #}
```

## URL Validation Functions

### cms.collection.hasTemplateUrl()

Check if a collection uses templated URLs.

```twig
{% if cms.collection.hasTemplateUrl('blog') %}
    <p>This collection uses templated URLs</p>
{% endif %}
```

### cms.collection.validateUrlTemplateFields()

Validate URL template fields against the schema. Returns warnings about potential issues.

```twig
{% set validation = cms.collection.validateUrlTemplateFields('blog') %}

{% if validation.notIndexed is not empty %}
    <div class="warning">
        Fields not in index (won't work in sitemap/RSS):
        {{ validation.notIndexed | join(', ') }}
    </div>
{% endif %}

{% if validation.notRequired is not empty %}
    <div class="warning">
        Fields not required (may cause broken URLs):
        {{ validation.notRequired | join(', ') }}
    </div>
{% endif %}

{% if validation.prettyUrlDisabled %}
    <div class="warning">
        Pretty URLs are disabled - template syntax will be ignored
    </div>
{% endif %}
```

**Returns:**
```php
[
    'notIndexed' => ['category'],      // Fields not in schema index
    'notRequired' => ['category'],     // Fields not marked as required
    'prettyUrlDisabled' => false       // Whether prettyUrl is disabled
]
```

### cms.collection.objectUrlHasEmptySegments()

Check if an object's URL has empty segments (missing template data).

```twig
{% if cms.collection.objectUrlHasEmptySegments('blog', post) %}
    <div class="warning">
        This post has missing URL fields and won't appear in sitemaps
    </div>
{% endif %}
```

### cms.collection.urlTemplateFields()

Get the list of fields used in a collection's URL template.

```twig
{% set fields = cms.collection.urlTemplateFields('blog') %}
{# Returns: ['category', 'id'] for template /blog/{{ category }}/{{ id }} #}

<p>URL uses these fields: {{ fields | join(', ') }}</p>
```

## Best Practices

### 1. Use Indexed Fields

Fields used in URL templates should be added to the schema's `index` array. This ensures the field data is available when generating sitemaps and RSS feeds.

```json
{
    "id": "blog",
    "index": ["title", "category", "date"]
}
```

### 2. Use Required Fields

Mark URL template fields as required to prevent broken URLs from missing data:

```json
{
    "id": "blog",
    "required": ["title", "category"]
}
```

### 3. Pass Full Objects When Possible

When iterating over objects, pass the full object to URL functions for better performance:

```twig
{# Good - uses existing object data #}
{% for post in cms.collection.objects('blog') %}
    <a href="{{ cms.collection.objectUrl('blog', post) }}">{{ post.title }}</a>
{% endfor %}

{# Less efficient - fetches object again #}
{% for post in cms.collection.objects('blog') %}
    <a href="{{ cms.collection.objectUrl('blog', post.id) }}">{{ post.title }}</a>
{% endfor %}
```

### 4. Always Use Canonical Redirects

When using templated URLs, implement canonical redirects to handle old URL formats:

```twig
{# At the top of your object detail template #}
{{ cms.collection.redirectToCanonicalUrl('blog', post) }}
```

### 5. Handle Empty Segments

Objects with missing template data will have broken URLs. The sitemap and RSS builders automatically skip these objects, but you may want to warn content editors:

```twig
{% if cms.collection.objectUrlHasEmptySegments('blog', post) %}
    {# Show warning or handle gracefully #}
{% endif %}
```

## Sitemap and RSS Feed Integration

Objects with valid templated URLs are automatically included in sitemaps and RSS feeds. Objects with empty URL segments (missing template data) are automatically excluded.

```twig
{# Sitemap generation handles templated URLs automatically #}
{{ cms.sitemap('blog') }}

{# RSS feed also uses templated URLs #}
{{ cms.rssFeed('blog') }}
```

## Migration from Simple URLs

When migrating from simple URLs to templated URLs:

1. Update the collection's `url` setting with the template
2. Ensure all required fields have values in existing objects
3. Implement `redirectToCanonicalUrl()` to handle old URL formats
4. Update any hardcoded URLs in your templates
5. Submit updated sitemap to search engines
