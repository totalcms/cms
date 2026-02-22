# Collection Settings Reference

This document provides a comprehensive reference for all settings available in a Total CMS collection's metadata (`.meta.json` file). These settings control how collections behave, how they appear in the admin interface, and how their data can be accessed.

## Overview

Collection settings are stored in the `.meta.json` file within each collection's directory:

```
/tcms-data/{collection-id}/.meta.json
```

You can edit these settings:
1. **Admin Interface** - Edit the collection in the admin panel
2. **Direct File Edit** - Modify the `.meta.json` file directly
3. **API** - Update via the collection management API

### Accessing Collection Metadata in Twig

```twig
{# Get collection metadata #}
{% set meta = cms.collection('team') %}

{# Access specific settings #}
{{ meta.name }}
{{ meta.labelPlural }}
{{ meta.manualSort.position | join(', ') }}
```

---

## Basic Settings

### id

**Type:** `string` (slug format)
**Required:** Yes

The unique identifier for this collection. Used in URLs, API endpoints, and file storage paths.

```json
{
    "id": "blog-posts"
}
```

**Constraints:**
- Must be URL-safe (lowercase, hyphens, no spaces)
- Cannot be changed after creation without data migration
- Cannot use reserved names: `templates`, `logs`, `schemas`, `.schemas`

---

### name

**Type:** `string`
**Required:** Yes

The human-readable display name for the collection, shown in the admin interface.

```json
{
    "name": "Blog Posts"
}
```

---

### schema

**Type:** `string` (slug format)
**Required:** Yes

The schema that objects in this collection conform to. Determines available fields and validation rules.

```json
{
    "schema": "blog"
}
```

**Built-in schemas:**
- `blog` - Blog posts with title, content, date, author
- `image` - Single images with metadata
- `gallery` - Image galleries
- `file` - File uploads
- `depot` - File storage depots
- `text` - Simple text content
- `styledtext` - Rich text content
- `number` - Numeric values
- `date` - Date/time values
- `toggle` - Boolean toggles
- `color` - Color values
- `url` - URL links
- `email` - Email addresses
- `svg` - SVG graphics
- `feed` - RSS/Atom feeds

Custom schemas can be created in `/tcms-data/.schemas/`.

---

### description

**Type:** `string`
**Required:** No

A description of the collection's purpose. Shown in the admin interface.

```json
{
    "description": "Published articles and news posts for the company blog."
}
```

**Default:** Auto-generated as "A collection of {id} objects that conform to the {schema} schema."

---

### queueRebuildOnSave

**Type:** `boolean`
**Required:** No
**Default:** `false`

When enabled, saving objects queues an index rebuild instead of rebuilding immediately. Useful for large collections where immediate rebuilds would be slow.

```json
{
    "queueRebuildOnSave": true
}
```

**When to enable:**
- Collections with 1000+ objects
- Collections with complex computed fields
- High-traffic admin environments

---

## URL Settings

### url

**Type:** `string`
**Required:** No

The URL path to individual object pages on your site. Used for generating links to objects.

```json
{
    "url": "/blog/post.php"
}
```

**Usage in Twig:**
```twig
{# Generates link like /blog/post.php?id=my-post #}
<a href="{{ post.url }}">{{ post.title }}</a>
```

---

### prettyUrl

**Type:** `boolean`
**Required:** No
**Default:** `false`

When enabled, object URLs use path-style format instead of query parameters.

```json
{
    "url": "/blog",
    "prettyUrl": true
}
```

**URL formats:**
- `prettyUrl: false` → `/blog/post.php?id=my-post`
- `prettyUrl: true` → `/blog/my-post`

---

## Dashboard Settings

### category

**Type:** `string`
**Required:** No

Groups collections in the admin sidebar. Collections with the same category appear together under a collapsible heading.

```json
{
    "category": "Content"
}
```

**Example categories:**
- "Content" - Blog posts, pages, articles
- "Media" - Images, galleries, files
- "Settings" - Configuration objects
- "Users" - User-related collections

---

### labelPlural

**Type:** `string`
**Required:** No

Custom plural term for collection items, used throughout the admin interface.

```json
{
    "labelPlural": "Articles"
}
```

**Default by schema:**
| Schema | Default Plural |
|--------|---------------|
| blog | Posts |
| image | Images |
| gallery | Galleries |
| file | Files |
| text | Content |
| (other) | Objects |

---

### labelSingular

**Type:** `string`
**Required:** No

Custom singular term for collection items.

```json
{
    "labelSingular": "Article"
}
```

**Usage in admin:**
- "New Article" button
- "Edit Article" page title
- "Delete this Article?" confirmation

---

### sortBy

**Type:** `string`
**Required:** No
**Default:** `"id"`

The default property used to sort objects in the admin collection view.

```json
{
    "sortBy": "date"
}
```

**Common sort properties:**
- `id` - Alphabetical by ID
- `date` - By date field
- `title` - Alphabetical by title
- `onCreate` - By creation date
- `onUpdate` - By last modified date

---

### reverseSort

**Type:** `boolean`
**Required:** No
**Default:** `false`

Reverses the default sort order (descending instead of ascending).

```json
{
    "sortBy": "date",
    "reverseSort": true
}
```

**Common patterns:**
- `sortBy: "date", reverseSort: true` - Newest first
- `sortBy: "title", reverseSort: false` - A-Z
- `sortBy: "priority", reverseSort: false` - Lowest priority first

---

## Access Control

### groups

**Type:** `array<string>`
**Required:** No
**Default:** `[]`

Access groups that can access files in this collection. Used for controlling download/stream access to collection files.

```json
{
    "groups": ["members", "premium"]
}
```

**Usage:**
- Empty array = public access
- Specified groups = only authenticated users in those groups

---

### publicOperations

**Type:** `array<string>`
**Required:** No
**Default:** `[]`

Operations that unauthenticated users can perform on this collection via the API.

```json
{
    "publicOperations": ["read"]
}
```

**Available operations:**
- `create` - Create new objects
- `read` - Read/list objects
- `update` - Modify existing objects
- `delete` - Remove objects

**Security considerations:**
- Only enable what's necessary
- `create` without authentication can lead to spam
- `update` and `delete` are rarely appropriate for public access
- Consider rate limiting for public endpoints

**Common patterns:**
```json
// Public read-only (blog, portfolio)
{"publicOperations": ["read"]}

// Public form submissions (contact forms)
{"publicOperations": ["create"]}

// Fully protected (admin-only)
{"publicOperations": []}
```

---

## Sorting Settings

### manualSort

**Type:** `object<string, array<string>>`
**Required:** No
**Default:** `{}`

Defines custom sort orders for the `manualSort` Twig filter. Keys are property names, values are arrays of values in the desired order.

```json
{
    "manualSort": {
        "position": ["ceo", "cfo", "cmo", "vp", "director", "manager"],
        "department": ["executive", "engineering", "sales", "marketing"]
    }
}
```

**Usage in Twig:**
```twig
{# Automatic lookup using collection option #}
{% set team = cms.objects("team") | manualSort({
    property: 'position',
    collection: 'team',
    remainder: {property: 'lastName'}
}) %}
```

**Benefits:**
- Sort orders can be edited in the admin without changing templates
- Multiple sort orders can be defined for different use cases
- Centralized configuration for consistent ordering

See [Collection Filtering - manualSort Filter](docs/twig/collection-filtering#manualsort-filter) for complete usage documentation.

---

## Form Customization

### formSettings

**Type:** `object`
**Required:** No
**Default:** `{}`

Customizes the behavior and appearance of object creation/edit forms.

```json
{
    "formSettings": {
        "helpStyle": "tooltip",
        "helpOnFocus": true,
        "newActions": [
            {"action": "redirect-object", "link": "/admin/collections/blog/{id}"}
        ],
        "editActions": [
            {"action": "refresh"}
        ]
    }
}
```

**Available options:**

| Option | Type | Description |
|--------|------|-------------|
| `helpStyle` | string | Help text display: `"label"`, `"tooltip"`, `"box"` |
| `helpOnHover` | boolean | Show help on field hover |
| `helpOnFocus` | boolean | Show help on field focus |
| `newActions` | array | Actions after creating new object |
| `editActions` | array | Actions after editing object |
| `deleteActions` | array | Actions after deleting object |

See [Collection Form Settings](docs/admin/collection-form-settings) for complete documentation on form actions and help styles.

---

## Schema Overrides

### properties

**Type:** `object<string, object>`
**Required:** No
**Default:** `{}`

Override schema property attributes for this collection. Allows customizing field labels, help text, options, and settings without modifying the schema itself.

```json
{
    "properties": {
        "title": {
            "label": "Headline",
            "placeholder": "Enter a compelling headline..."
        },
        "category": {
            "options": [
                {"label": "News", "value": "news"},
                {"label": "Tutorial", "value": "tutorial"},
                {"label": "Review", "value": "review"}
            ]
        },
        "featured": {
            "help": "Featured articles appear on the homepage carousel."
        }
    }
}
```

**Overridable attributes:**
- `label` - Field label
- `help` - Help text
- `placeholder` - Placeholder text
- `field` - Field type
- `options` - Select/checkbox options
- `settings` - Field-specific settings

---

### customProperties

**Type:** `object<string, object>`
**Required:** No
**Default:** `{}`

Object-specific property overrides. Allows different field configurations for specific objects within the collection.

```json
{
    "customProperties": {
        "homepage": {
            "title": {
                "label": "Homepage Title",
                "help": "This title appears in the browser tab."
            }
        },
        "contact": {
            "content": {
                "settings": {
                    "rows": 10
                }
            }
        }
    }
}
```

**Structure:** `customProperties.{objectId}.{propertyName}.{attribute}`

**Use cases:**
- Different labels for special objects
- Custom options for specific items
- Longer text areas for certain content

---

## Statistics (Read-Only)

These fields are automatically maintained by Total CMS and should not be manually edited.

### count

**Type:** `integer`
**Read-only:** Yes

Total number of objects ever created in this collection (includes deleted objects).

```json
{
    "count": 147
}
```

---

### totalObjects

**Type:** `integer`
**Read-only:** Yes

Current number of active objects in the collection.

```json
{
    "totalObjects": 125
}
```

**Note:** A value of `-1` indicates the count hasn't been calculated yet.

---

### lastUpdated

**Type:** `string` (ISO 8601 datetime)
**Read-only:** Yes

Timestamp of the most recent object modification in the collection.

```json
{
    "lastUpdated": "2024-01-15T14:30:00+00:00"
}
```

---

## Complete Example

A fully configured collection metadata file:

```json
{
    "id": "team",
    "name": "Team Members",
    "schema": "team",
    "description": "Company team members and their profiles.",

    "url": "/about/team",
    "prettyUrl": true,

    "category": "Content",
    "labelPlural": "Team Members",
    "labelSingular": "Team Member",
    "sortBy": "lastName",
    "reverseSort": false,

    "groups": [],
    "publicOperations": ["read"],

    "queueRebuildOnSave": false,

    "manualSort": {
        "position": ["ceo", "cfo", "cmo", "vp", "director", "manager"],
        "department": ["executive", "engineering", "sales"]
    },

    "formSettings": {
        "helpStyle": "tooltip",
        "helpOnFocus": true,
        "newActions": [
            {"action": "redirect-object", "link": "/admin/collections/team/{id}"}
        ],
        "editActions": [
            {"action": "refresh"}
        ]
    },

    "properties": {
        "position": {
            "options": [
                {"label": "CEO", "value": "ceo"},
                {"label": "CFO", "value": "cfo"},
                {"label": "CMO", "value": "cmo"},
                {"label": "VP", "value": "vp"},
                {"label": "Director", "value": "director"},
                {"label": "Manager", "value": "manager"},
                {"label": "Staff", "value": "staff"}
            ]
        }
    },

    "customProperties": {},

    "count": 24,
    "totalObjects": 22,
    "lastUpdated": "2024-01-15T14:30:00+00:00"
}
```

---

## See Also

- [Collection Form Settings](docs/admin/collection-form-settings) - Detailed form customization
- [Collection Filtering](docs/twig/collection-filtering) - Filtering and sorting collections
- [Field Settings](docs/fields/styled-text) - Schema field configuration
- [Twig Filters Reference](docs/twig/filters) - All available Twig filters
