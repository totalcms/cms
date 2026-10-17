---
title: "Collection Settings Reference"
description: "Complete reference for Total CMS collection .meta.json settings including URL configuration, dashboard display, access control, sorting, and schema overrides."
---

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

### Accessing Collection Settings in Twig

```twig
{# Get collection metadata #}
{% set meta = cms.collection.get('team') %}

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

### singleton

Marks the collection as holding exactly **one** object — ideal for site-wide settings, global config, or a single homepage record.

```json
{
    "singleton": true
}
```

When enabled:

- Clicking the collection (in the Collection view or QuickNav) opens its one object directly — no one-row list.
- An empty singleton opens the new-object form; once saved, the object is reused on every visit.
- The object's id is **always forced to the collection id**, so its edit URL is a clean `/collections/{collection}/{collection}`.
- The "Add new" button is hidden — a singleton can't gain a second object.

**Converting an existing collection:** flipping `singleton` on is non-destructive. If the collection already has more than one object, singleton behavior stays dormant (the normal list is shown with a notice) until you delete it down to one — nothing is removed automatically. With exactly one object, it is adopted and re-keyed to the collection id.

---

### format

**Type:** `string`
**Required:** No
**Default:** `"json"`

The file format objects in this collection are stored in: `json` (each object is `{id}.json`) or `markdown` (each object is `{id}.md`, YAML frontmatter plus a body from the schema's `content` property).

```json
{
    "format": "markdown"
}
```

**Fixed after creation.** Choose the format when creating the collection; the setting shows disabled in the form afterwards, with a hint pointing at `tcms collection:convert`. Posting a changed `format` through the API is rejected with a 400 — the setting and the files on disk must always agree, and only the convert command changes both. See [Storage Format](docs/collections/storage-format) for the file layout, body rules, and the convert command.

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
- Public counters (likes, "was this helpful") are not a collection operation: set `publicIncrement: true` on the number field instead — see [Number and Range Fields](docs/fields/number-range)

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

### publicRegistration

**Type:** `boolean`
**Required:** No
**Default:** `false`

Lets anonymous visitors create their own account in this collection through [`POST /admin/register/{collection}`](docs/forms/options#public-registration-forms). Only collections whose schema is `auth` or inherits it have it.

```json
{
    "publicRegistration": true
}
```

Switch it on with **Allow Public Registration** in the **Public Registration** section of the collection form. The field is hidden on other collections, and on the new-collection form until the collection is saved. With it off the endpoint returns `403` "Public registration is not enabled for this collection." Installs that opted collections in through the older `config.auth.publicRegistration` list keep working; new setups use this toggle.

An enabled collection can also be [impersonated](docs/operations/impersonation), like the default auth collection.

Turning it on reveals two more fields: **Require Email Verification** (`requireEmailVerification`, off by default) and **Registration Groups** (below).

---

### requireEmailVerification

**Type:** `boolean`
**Required:** No
**Default:** `false`

New registrants are created inactive and must click a link in a verification email before they can sign in. Shown as **Require Email Verification** once [`publicRegistration`](#publicregistration) is on. With it off, a registrant is signed in as soon as the account is created. See [Email Verification](docs/forms/options#email-verification) for the full flow.

```json
{
    "publicRegistration": true,
    "requireEmailVerification": true
}
```

---

### registrationGroups

**Type:** `array<string>`
**Required:** No
**Default:** `[]`

Access groups given to every registrant. Shown as **Registration Groups**, a list of access groups, once [`publicRegistration`](#publicregistration) is on.

```json
{
    "publicRegistration": true,
    "registrationGroups": ["members", "newsletter"]
}
```

A registrant receives the global `publicRegistrationGroup` (auth settings) plus these groups, with duplicates removed. A `groups` value submitted in the registration form is always ignored, so a visitor cannot grant themselves access.

`admin` is never granted this way: it is dropped from the list when a registrant is created. Only super admins and API keys may change `publicRegistration` and `registrationGroups`; a request from any other user that would change either is refused with `403`, whatever collection access their groups grant.

---

### ownerProperty

**Type:** `string`
**Required:** No
**Default:** `""`

The property that holds the id of the user who owns each object. Setting it makes this an **owned collection**: every user who is not a super admin is limited to the objects they own, and the server writes their user id into this property when they create one.

```json
{
    "ownerProperty": "owner"
}
```

Pick it from the **Owner Property** select on the collection form, which lists the schema's string properties and lists of strings (not galleries or other lists of objects); choose "None" to clear it. The list is empty on a new collection until it is saved with its schema. The property must exist in the schema and be listed in the schema's `index`. It can be a string or a list of strings. A plain text field is enough, and a `relational` field pointing at the users collection makes the admin table show names. The stored value is the user id alone. Saving a collection whose `ownerProperty` is not a string property or a list of strings, is not in the schema, or is not in the `index` is rejected with a 400 that names the problem, so add the property to the schema first.

- **String or list.** A string holds one user id. A list (`type: array` with string items, or the `list` property type) holds several, and each listed user owns the object. The check is an exact string comparison between the user's id and the stored value, or each entry of it. On create the server stamps `[userId]` (an anonymous public submission stamps `[]`). Members cannot change the field on update: what they send is reverted to the stored value, so only super admins and API keys add or remove co-owners. A `multicheckbox`, `multiselect` or `checklist` array works too, but the admin form shows a user id that is not one of its options, so prefer `list`.
- **In the index.** The property must be in the schema's [`index`](docs/schemas/reference#index) array, and saving the collection is rejected otherwise. Members' queries and index reads are scoped by matching this property on the collection index, by exact comparison with the user's id (case-sensitive, no wildcards).

Because the owner value does not record which auth collection the id came from, a site with several auth collections must keep user ids unique across them, or set [`ownerCollection`](#ownercollection).

---

### ownerCollection

**Type:** `string`
**Required:** No
**Default:** `""`

The auth collection whose members are scoped owners of this collection. Pick it from the **Owner Collection** select, next to **Owner Property**; "Any auth collection" leaves it empty. It only has an effect when `ownerProperty` is set.

```json
{
    "ownerProperty": "owner",
    "ownerCollection": "members"
}
```

When set, a signed-in user from any other auth collection is treated like an anonymous caller on this collection: refused with `403` on every object, list, create and admin-form route (access log `reason: owner`) unless the operation is in `publicOperations`, where the caller is treated as anonymous; a public create by them stores an empty owner. Super admins and API keys are unaffected. When empty, the user's auth collection is ignored, so user ids must be unique across auth collections. Saving a collection whose `ownerCollection` is missing or is not an auth collection (its schema is not `auth` and does not inherit from it) is rejected.

**Owned collections**

Access groups grant operations per collection. On an owned collection the same grant applies to the user's own objects only:

- **Create** — the owner is stamped server-side with the acting user's id, whatever the request sent. An anonymous public submission (`publicOperations: ["create"]`) stores an empty owner.
- **Read, update, delete, property and file routes** — refused with `403` when the object belongs to someone else. A write that names an object id that does not exist is refused too (a read gets the usual `404`): members create through `POST /api/collections/{collection}`. The response is the same "Access denied" as a group refusal; the access log says `reason: owner` (or `reason: group` when the access group refused first).
- **List and query** — `GET /api/collections/{c}/index` and `/query` return only the user's own objects. `ids=`, `filter=` and `search=` cannot widen that.
- **Bulk delete, reindex and clone** — refused for scoped members; super admins, API keys and admin bearer tokens are unaffected. Bulk and reindex cannot be scoped; a clone would copy another object's owner.
- **Whole-collection routes are an allow-list.** A scoped member can create (`POST /api/collections/{collection}`), list (`/query` and `/index`, both scoped) and open the admin collection page (not its export, import, factory, sitemap or feeds pages). Every other route that has no object id is refused with `403`: export (`/api/export/collections/{collection}/...`), report (`/api/report/collections/{collection}/...`), import (`/api/import/collections/{collection}/...`), reindex, bulk delete, and duplicating another object from the admin ("duplicate" on the add page), as well as `object-clone`.
- The owner property itself cannot be changed by its owner. It is protected by being named here; see [protected](docs/schemas/validation#protected) for the per-field version.
- **Protected properties on property routes.** On every collection, owned or not, a non-admin write to a protected property (or the owner property) through any property route — `PUT`/`PATCH`/`DELETE .../{id}/{property}`, deck items, files, folders, meta, counters and `/api/upload/*` — is refused with `403`; the access log says `reason: protected`.
- **Forced values on every write.** A [`protected: {"value": X}`](docs/schemas/validation#protected) property is written as `X` on every authenticated non-admin write (session or OAuth user) — body routes, property routes, deck items, file and upload routes, meta writes and property deletes — so uploading a new screenshot to an approved object sends it back to review. Anonymous public submissions through the object body routes are forced too. The exceptions: counter increments and decrements (a tick is not an edit), property, deck and file routes reached as a public submission under a public `update`, and [registration](docs/forms/options#public-registration-forms), which strips protected fields but does not apply forced values.
- **Clone and import on protected collections.** On every collection, owned or not, that has protected properties (or an owner property), a non-admin clone (`POST .../{id}/clone`) and every collection import (`/api/import/collections/{collection}/...`: CSV, JSON, deck CSV, deck JSON and factory) are refused with `403` and `reason: protected`: both copy or upsert whole objects with no field policy. A non-admin clone whose body names a different target `collection` is refused on any collection. Under a public `create`, neither kind of clone is public.

Who is scoped: everyone who is not a super admin (a member of the `admin` group in the default auth collection), whether they sign in through a custom auth collection or the dashboard. A dashboard editor on an owned collection therefore sees only their own objects in the collection table. Staff who must moderate every object are super admins. API keys and OAuth tokens issued to admins are untouched; an OAuth token issued to a non-admin is scoped like that user's session.

Over MCP an owned collection is admin-only. It is not listed by `list_collections`, the cross-collection tools, `resources/list`, tool catalogs or schema-defined tools, and any tool or resource that names it is refused for authenticated and public callers. Data views keep their own `mcp.access`, since a view is an admin's explicit publication. Over MCP, collections with protected properties accept writes (`create_object`, `update_object`, `patch_object`) from admin callers only.

**Public operations bypass ownership.** Public requests skip access control entirely, so any `publicOperations` other than `create` — public `read`, `update` or `delete` — skip the owner check too: anyone could read, change or delete any member's object. An owned collection should enable public `create` at most. (Public `create` does not open clone on an owned collection, or on any collection with protected properties.)

Pair this with [`protected`](docs/schemas/validation#protected) on a `draft` field — `"protected": {"value": true}` — and every member edit goes back to the review queue while an admin's approval is the only way out of it. The [member portal pattern](docs/forms/options#member-portals-owned-collections) shows the form side.

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
{% set team = cms.collection.objects("team") | manualSort({
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

See [Collection Form Settings](docs/collections/form-settings) for complete documentation on form actions and help styles.

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
        "categories": {
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

The **content** timestamp: when an object in this collection was last created, updated, or deleted. Says nothing about the collection's settings — see `updated` for that.

```json
{
    "lastUpdated": "2024-01-15T14:30:00+00:00"
}
```

---

### updated

**Type:** `string` (ISO 8601 datetime)
**Read-only:** Yes

The **settings** timestamp: when this collection's configuration last changed (URL, MCP card, sitemap, access, schema overrides…). It is delta-stamped — the save compares the configuration before writing, so content activity and counter bumps never move it. Sync uses it to tell which side has the newer configuration, and preserves it verbatim when settings arrive via sync.

```json
{
    "updated": "2026-07-30T09:12:00+00:00"
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
    "ownerProperty": "owner",

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

- [Collection Form Settings](docs/collections/form-settings) - Detailed form customization
- [Collection Filtering](docs/twig/collection-filtering) - Filtering and sorting collections
- [Field Settings](docs/fields/styled-text) - Schema field configuration
- [Twig Filters Reference](docs/twig/filters) - All available Twig filters
