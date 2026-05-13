---
title: "Twig Auth Reference"
description: "Reference for the cms.auth namespace providing login/logout, user info, session data, access control, and passkey management."
---

# cms.auth

The auth adapter provides authentication, user management, and fine-grained access control for templates.

## Login & Logout

### login()

Get a login URL. Supports collection-specific login and custom redirect URLs.

```twig
{# Default login with redirect back to current page #}
{{ cms.auth.login() }}

{# Collection-specific login #}
{{ cms.auth.login('members') }}

{# Login with no redirect #}
{{ cms.auth.login('', '') }}

{# Login with custom redirect #}
{{ cms.auth.login('', '/welcome') }}

{# Collection login with custom redirect #}
{{ cms.auth.login('members', '/dashboard') }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `collection` | string | `''` | Collection to authenticate against |
| `redirect` | string\|null | `null` | Redirect URL after login (`null` = current page, `''` = no redirect) |

### logout()

Get a logout URL with optional redirect.

```twig
{{ cms.auth.logout() }}
{{ cms.auth.logout('/goodbye') }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `redirect` | string | `''` | Redirect URL after logout |

## User Information

### userData()

Get the current logged-in user's data as an array.

```twig
{% set user = cms.auth.userData() %}
{% if user %}
    <p>Welcome, {{ user.name }}</p>
    <p>Email: {{ user.email }}</p>
{% endif %}
```

### userLoggedIn()

Check if a user is currently logged in.

```twig
{% if cms.auth.userLoggedIn() %}
    <a href="{{ cms.auth.logout() }}">Sign Out</a>
{% else %}
    <a href="{{ cms.auth.login() }}">Sign In</a>
{% endif %}

{# Check login for specific collection #}
{% if cms.auth.userLoggedIn('members') %}
    <p>Member area content</p>
{% endif %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `collection` | string | `''` | Check login status for specific collection |

### isAdmin()

Check if the current user is an admin. Admin users bypass all access controls.

```twig
{% if cms.auth.isAdmin() %}
    <a href="{{ cms.dashboard }}">Admin Panel</a>
{% endif %}
```

### sessionData()

Get a value from the session by key.

```twig
{{ cms.auth.sessionData('theme') }}
{{ cms.auth.sessionData('last_visited') }}
```

**Returns:** `string|null` — the session value or null if not found

## Access Control

### userHasAccess()

Check if the current user belongs to one or more access groups.

```twig
{# Single group #}
{% if cms.auth.userHasAccess('editors') %}
    <button>Edit Page</button>
{% endif %}

{# Multiple groups (user must match at least one) #}
{% if cms.auth.userHasAccess(['editors', 'admins']) %}
    <div class="admin-tools">...</div>
{% endif %}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `groups` | string\|array | Group name or array of group names |
| `collection` | string | Optional collection context (default: `''`) |

### canAccessCollection()

Check if the current user can perform a CRUD operation on a specific collection.

```twig
{% if cms.auth.canAccessCollection('blog', 'read') %}
    {# Show blog posts #}
{% endif %}

{% if cms.auth.canAccessCollection('products', 'create') %}
    <a href="/products/new">Add Product</a>
{% endif %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `collection` | string | required | Collection identifier |
| `operation` | string | `'read'` | CRUD operation: `read`, `create`, `update`, `delete` |

### accessibleCollections()

Get a list of collection IDs the current user can access with a given operation.

```twig
{% set readable = cms.auth.accessibleCollections('read') %}
{% for colId in readable %}
    <p>{{ colId }}</p>
{% endfor %}
```

### canAccessCollectionsOperation()

Check if the user can perform an operation on collections in general (not a specific collection).

```twig
{% if cms.auth.canAccessCollectionsOperation('create') %}
    <a href="/collections/new">New Collection</a>
{% endif %}
```

### canAccessCollectionMeta()

Check if the user can perform an operation on a specific collection's metadata.

```twig
{% if cms.auth.canAccessCollectionMeta('blog', 'update') %}
    <a href="/admin/collections/blog/settings">Collection Settings</a>
{% endif %}
```

### canAccessCollectionsMetaOperation()

Check if the user can perform metadata operations on collections in general.

```twig
{% if cms.auth.canAccessCollectionsMetaOperation('read') %}
    {# Show collection settings link #}
{% endif %}
```

### canAccessSchema()

Check if the user can perform a CRUD operation on a specific schema.

```twig
{% if cms.auth.canAccessSchema('blog', 'update') %}
    <a href="/admin/schemas/blog">Edit Schema</a>
{% endif %}
```

### canAccessSchemasOperation()

Check if the user can perform operations on schemas in general.

```twig
{% if cms.auth.canAccessSchemasOperation('create') %}
    <a href="/admin/schemas/new">New Schema</a>
{% endif %}
```

### canAccessTemplates()

Check if the user can access templates.

```twig
{% if cms.auth.canAccessTemplates() %}
    <a href="/admin/templates">Templates</a>
{% endif %}
```

### canAccessUtil()

Check if the user can access a specific utility page.

```twig
{% if cms.auth.canAccessUtil('jumpstart') %}
    <a href="/admin/utils/jumpstart">JumpStart</a>
{% endif %}
```

### canAccessUtils()

Check if the user can access any utility pages.

```twig
{% if cms.auth.canAccessUtils() %}
    <a href="/admin/utils">Utilities</a>
{% endif %}
```

### canAccessMailer()

Check if the user can access the mailer collection.

```twig
{% if cms.auth.canAccessMailer() %}
    <a href="/admin/mailer">Mailer</a>
{% endif %}
```

### canAccessPlayground()

Check if the user can access the playground.

```twig
{% if cms.auth.canAccessPlayground() %}
    <a href="/admin/playground">Playground</a>
{% endif %}
```

### canAccessDataViews()

Check if the user can access data views.

```twig
{% if cms.auth.canAccessDataViews() %}
    <a href="/admin/dataviews">Data Views</a>
{% endif %}
```

### canAccessDocs()

Check if the user can access documentation.

```twig
{% if cms.auth.canAccessDocs() %}
    <a href="/admin/docs">Documentation</a>
{% endif %}
```

## Password Protection

### verifyFilePassword()

Verify a password for accessing a protected file or depot item.

```twig
{% if cms.auth.verifyFilePassword(password, 'documents', docId, 'file') %}
    <a href="{{ cms.media.download(docId, {pwd: password}) }}">Download</a>
{% else %}
    <p>Invalid password</p>
{% endif %}

{# For depot files, include the filename #}
{% if cms.auth.verifyFilePassword(password, 'files', objId, 'depot', 'report.pdf') %}
    <a href="{{ cms.media.depotDownload(objId, 'report.pdf', {pwd: password}) }}">Download Report</a>
{% endif %}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `password` | string | required | Password to verify |
| `collection` | string | required | Collection identifier |
| `id` | string | required | Object identifier |
| `property` | string | required | Property name |
| `name` | string\|null | `null` | Filename (for depot files) |

## Passkeys

### passkeyManager()

Render the passkey management UI for registering and managing WebAuthn passkeys.

```twig
<div class="security-settings">
    <h3>Passkeys</h3>
    {{ cms.auth.passkeyManager()|raw }}
</div>
```
