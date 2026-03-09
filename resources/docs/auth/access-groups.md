---
title: "Access Groups"
description: "Configure access groups in Total CMS to control CRUD permissions for collections, restrict admin dashboard access, and manage user roles."
---

# Access Groups

Access groups provide fine-grained permission control for Total CMS, allowing you to restrict what users can access and modify in both the admin dashboard and via the REST API.

## Overview

Access groups work at two levels:

1. **Middleware Enforcement**: Routes are protected by middleware that checks permissions before allowing access
2. **UI Controls**: Template helper functions hide/show UI elements based on permissions

Super admin users (members of the `admin` group in the default `auth` collection) bypass all access checks automatically.

## Access Group Structure

Access groups are defined in `tcms-data/.system/access-groups.json`:

```json
{
    "groups": [
        {
            "id": "editor",
            "description": "Blog and news editor",
            "permissions": {
                "collectionsMeta": {
                    "operations": ["read"],
                    "all": false,
                    "allowed": ["blog", "news"]
                },
                "collections": {
                    "operations": ["create", "read", "update", "delete"],
                    "all": false,
                    "allowed": ["blog", "news"]
                },
                "schemas": {
                    "operations": ["read"],
                    "all": false,
                    "allowed": ["blog", "news"]
                },
                "templates": true,
                "mailer": false,
                "playground": true,
                "docs": true,
                "utils": {
                    "all": false,
                    "allowed": ["cache-manager"]
                },
                "settings": {
                    "all": false,
                    "allowed": []
                }
            }
        }
    ]
}
```

## CRUD Operations

Total CMS uses CRUD (Create, Read, Update, Delete) operations for fine-grained permission control:

- **`create`** - Create new resources/objects
- **`read`** - View/list/fetch resources
- **`update`** - Modify existing resources
- **`delete`** - Delete/remove resources

These operations replace the older HTTP method approach and provide clearer, more intuitive permission control.

## Permission Types

### Collections

Collections have two separate permission levels:

#### Collection Metadata (`collectionsMeta`)

Controls access to managing collection definitions (creating/editing/deleting collections themselves):

```json
"collectionsMeta": {
    "operations": ["read", "update"],
    "all": false,
    "allowed": ["blog", "news"]
}
```

- **`create`** - Create new collections
- **`read`** - View collection definitions
- **`update`** - Edit collection settings
- **`delete`** - Delete collections

#### Collection Objects (`collections`)

Controls access to objects within collections:

```json
"collections": {
    "operations": ["create", "read", "update", "delete"],
    "all": true,
    "allowed": []
}
```

- **`create`** - Create new objects
- **`read`** - View/list objects
- **`update`** - Edit objects
- **`delete`** - Delete objects
- **`all`** - If `true`, access all collections; if `false`, only those in `allowed`
- **`allowed`** - Array of specific collection IDs

### Schemas

```json
"schemas": {
    "operations": ["create", "read", "update", "delete"],
    "all": false,
    "allowed": ["blog", "product"]
}
```

- **`create`** - Create new schemas
- **`read`** - View schemas
- **`update`** - Edit schemas
- **`delete`** - Delete schemas
- **`all`** - If `true`, access all schemas; if `false`, only those in `allowed`
- **`allowed`** - Array of specific schema names

### Templates

```json
"templates": true  // or false
```

Simple boolean for full access or no access to templates. Templates don't have granular CRUD permissions.

### Settings

```json
"settings": {
    "all": false,
    "allowed": ["general", "cache"]
}
```

- **`all`** - If `true`, access all settings sections
- **`allowed`** - Array of specific setting section names (e.g., "general", "cache", "auth", "mailer")

### Utils

```json
"utils": {
    "all": false,
    "allowed": ["cache-manager", "jumpstart"]
}
```

- **`all`** - If `true`, access all utility pages
- **`allowed`** - Array of specific utility page names

### Boolean Permissions

Simple `true`/`false` for features without granular control:

- **`mailer`** - Access to mailer/email functionality
- **`playground`** - Access to Twig playground
- **`docs`** - Access to documentation

## Twig Helper Functions

Total CMS provides helper functions to check permissions in your templates, allowing you to hide/show UI elements based on the current user's access groups.

### Collections

**Check specific collection object access:**
```twig
{% if cms.canAccessCollection('blog', 'read') %}
    <a href="/admin/collections/blog">View Blog Posts</a>
{% endif %}

{% if cms.canAccessCollection('blog', 'create') %}
    <a href="/admin/collections/blog/new">New Post</a>
{% endif %}

{% if cms.canAccessCollection('blog', 'update') %}
    <button>Edit Post</button>
{% endif %}

{% if cms.canAccessCollection('blog', 'delete') %}
    <button class="delete">Delete Post</button>
{% endif %}
```

**Check general collection object access:**
```twig
{% if cms.canAccessCollectionsOperation('read') %}
    <p>You can view collections</p>
{% endif %}
```

**Check collection metadata access:**
```twig
{% if cms.canAccessCollectionMeta('blog', 'read') %}
    <a href="/admin/collections/blog/settings">View Collection Settings</a>
{% endif %}

{% if cms.canAccessCollectionMeta('blog', 'update') %}
    <button>Edit Collection Settings</button>
{% endif %}

{% if cms.canAccessCollectionMeta('blog', 'delete') %}
    <button class="delete">Delete Collection</button>
{% endif %}
```

**Check general collection metadata access:**
```twig
{% if cms.canAccessCollectionsMetaOperation('read') %}
    <a href="/admin/collections">View Collections List</a>
{% endif %}

{% if cms.canAccessCollectionsMetaOperation('create') %}
    <a href="/admin/collections/new">New Collection</a>
{% endif %}
```

**Get list of accessible collections:**
```twig
{% for collection in cms.getAccessibleCollections('read') %}
    <li>{{ collection }}</li>
{% endfor %}
```

### Schemas

**Check specific schema access:**
```twig
{% if cms.canAccessSchema('blog', 'read') %}
    <a href="/admin/schemas/blog">View Blog Schema</a>
{% endif %}

{% if cms.canAccessSchema('blog', 'update') %}
    <a href="/admin/schemas/blog/edit">Edit Schema</a>
{% endif %}

{% if cms.canAccessSchema('blog', 'delete') %}
    <button class="delete">Delete Schema</button>
{% endif %}
```

**Check general schemas access:**
```twig
{% if cms.canAccessSchemasOperation('read') %}
    <a href="/admin/schemas">View Schemas</a>
{% endif %}

{% if cms.canAccessSchemasOperation('create') %}
    <a href="/admin/schemas/new">New Schema</a>
{% endif %}
```

### Templates

**Check templates access (boolean):**
```twig
{% if cms.canAccessTemplates() %}
    <a href="/admin/templates">Templates</a>
{% endif %}
```

### Utils

**Check specific utils page:**
```twig
{% if cms.canAccessUtil('cache-manager') %}
    <a href="/admin/utils/cache-manager">Cache Manager</a>
{% endif %}

{% if cms.canAccessUtil('jumpstart') %}
    <a href="/admin/utils/jumpstart">JumpStart</a>
{% endif %}
```

**Check general utils access:**
```twig
{% if cms.canAccessUtils() %}
    <a href="/admin/utils">Utils</a>
{% endif %}
```

### Boolean Permissions

**Check mailer access:**
```twig
{% if cms.canAccessMailer() %}
    <a href="/admin/mailer">Mailer</a>
{% endif %}
```

**Check playground access:**
```twig
{% if cms.canAccessPlayground() %}
    <a href="/admin/utils/twig-playground">Twig Playground</a>
{% endif %}
```

**Check docs access:**
```twig
{% if cms.canAccessDocs() %}
    <a href="/admin/docs">Documentation</a>
{% endif %}
```

### Admin Check

**Check if user is super admin:**
```twig
{% if cms.isAdmin() %}
    <div class="admin-only-feature">
        <a href="/admin/utils/access-groups">Manage Access Groups</a>
    </div>
{% endif %}
```

Super admins bypass all access checks and have full access to everything.

## Practical Examples

### Conditional Navigation Menu

```twig
<nav>
    {% if cms.canAccessCollectionsMetaOperation('read') %}
    <a href="/admin/collections">Collections</a>
    {% endif %}

    {% if cms.canAccessSchemasOperation('read') %}
    <a href="/admin/schemas">Schemas</a>
    {% endif %}

    {% if cms.canAccessTemplates() %}
    <a href="/admin/templates">Templates</a>
    {% endif %}

    {% if cms.canAccessUtils() %}
    <a href="/admin/utils">Utils</a>
    {% endif %}

    {% if cms.isAdmin() %}
    <a href="/admin/utils/access-groups">Access Groups</a>
    {% endif %}
</nav>
```

### Filtered Collection List

```twig
<ul>
{% for collection in collections %}
    {% if cms.canAccessCollection(collection.id, 'read') %}
    <li>
        <a href="/admin/collections/{{ collection.id }}">{{ collection.name }}</a>

        {% if cms.canAccessCollection(collection.id, 'create') %}
        <a href="/admin/collections/{{ collection.id }}/new">New Item</a>
        {% endif %}

        {% if cms.canAccessCollectionMeta(collection.id, 'update') %}
        <a href="/admin/collections/{{ collection.id }}/settings">Settings</a>
        {% endif %}
    </li>
    {% endif %}
{% endfor %}
</ul>
```

### Collection Object Actions

```twig
<div class="object-actions">
    {% if cms.canAccessCollection(collection, 'create') %}
    <a href="/admin/collections/{{ collection }}/new" class="btn">New Object</a>
    {% endif %}

    {% if cms.canAccessCollection(collection, 'update') %}
    <button class="btn" data-action="edit">Edit</button>
    {% endif %}

    {% if cms.canAccessCollection(collection, 'delete') %}
    <button class="btn btn-danger" data-action="delete">Delete</button>
    {% endif %}
</div>
```

### Schema Management Buttons

```twig
<div class="schema-actions">
    {% if cms.canAccessSchemasOperation('create') %}
    <a href="/admin/schemas/new" class="btn">New Schema</a>
    {% endif %}

    {% if cms.canAccessSchema(schema.id, 'update') %}
    <a href="/admin/schemas/{{ schema.id }}/edit" class="btn">Edit</a>
    {% endif %}

    {% if cms.canAccessSchema(schema.id, 'delete') %}
    <button class="btn btn-danger" data-schema="{{ schema.id }}">Delete</button>
    {% endif %}
</div>
```

## Common Access Group Configurations

### Full Admin

```json
{
    "id": "admin",
    "description": "Full administrative access",
    "permissions": {
        "collectionsMeta": {
            "operations": ["create", "read", "update", "delete"],
            "all": true,
            "allowed": []
        },
        "collections": {
            "operations": ["create", "read", "update", "delete"],
            "all": true,
            "allowed": []
        },
        "schemas": {
            "operations": ["create", "read", "update", "delete"],
            "all": true,
            "allowed": []
        },
        "templates": true,
        "mailer": true,
        "playground": true,
        "docs": true,
        "utils": {
            "all": true,
            "allowed": []
        },
        "settings": {
            "all": true,
            "allowed": []
        }
    }
}
```

### Content Editor

```json
{
    "id": "editor",
    "description": "Full CRUD access to specific collections",
    "permissions": {
        "collectionsMeta": {
            "operations": ["read"],
            "all": false,
            "allowed": ["blog", "news"]
        },
        "collections": {
            "operations": ["create", "read", "update", "delete"],
            "all": false,
            "allowed": ["blog", "news"]
        },
        "schemas": {
            "operations": ["read"],
            "all": false,
            "allowed": ["blog", "news"]
        },
        "templates": false,
        "mailer": false,
        "playground": true,
        "docs": true,
        "utils": {
            "all": false,
            "allowed": []
        },
        "settings": {
            "all": false,
            "allowed": []
        }
    }
}
```

### Read-Only Viewer

```json
{
    "id": "viewer",
    "description": "Read-only access to all collections",
    "permissions": {
        "collectionsMeta": {
            "operations": ["read"],
            "all": true,
            "allowed": []
        },
        "collections": {
            "operations": ["read"],
            "all": true,
            "allowed": []
        },
        "schemas": {
            "operations": ["read"],
            "all": true,
            "allowed": []
        },
        "templates": true,
        "mailer": false,
        "playground": true,
        "docs": true,
        "utils": {
            "all": false,
            "allowed": []
        },
        "settings": {
            "all": false,
            "allowed": []
        }
    }
}
```

### Single Collection Specialist

```json
{
    "id": "blogger",
    "description": "Full CRUD access to blog collection only",
    "permissions": {
        "collectionsMeta": {
            "operations": ["read"],
            "all": false,
            "allowed": ["blog"]
        },
        "collections": {
            "operations": ["create", "read", "update", "delete"],
            "all": false,
            "allowed": ["blog"]
        },
        "schemas": {
            "operations": ["read"],
            "all": false,
            "allowed": ["blog"]
        },
        "templates": false,
        "mailer": false,
        "playground": true,
        "docs": true,
        "utils": {
            "all": false,
            "allowed": []
        },
        "settings": {
            "all": false,
            "allowed": []
        }
    }
}
```

## Best Practices

1. **Use UI Controls Everywhere**: Always check permissions before showing links, buttons, or forms. Don't rely on middleware alone - good UX means hiding what users can't access.

2. **Check Appropriate Operations**: Use the appropriate CRUD operation for the action:
   - `read` for viewing/listing
   - `create` for creating new resources
   - `update` for modifying existing resources
   - `delete` for deleting resources

3. **Distinguish Collections from Collection Metadata**:
   - Use `canAccessCollection()` for working with objects within a collection
   - Use `canAccessCollectionMeta()` for managing collection definitions

4. **Graceful Degradation**: Hide buttons/actions users can't perform rather than showing disabled buttons.

5. **Admin-Only Features**: Use `cms.isAdmin()` for features that should never be delegated (like managing access groups, API keys, or user accounts).

6. **Operation-Level Checks**: Use general operation checks (e.g., `canAccessCollectionsOperation()`) for listing pages where no specific resource is selected yet.

7. **Principle of Least Privilege**: Grant users only the minimum permissions they need to accomplish their tasks.

## Admin-Only Routes

Some routes require super admin access and cannot be delegated via access groups:

- **Access Groups Management**: `/admin/utils/access-groups`
- **API Key Management**: `/admin/apikeys/*`
- **User Management**: `/admin/users/*`

These routes use `AdminOnlyMiddleware` which only allows super admin users.

## CRUD Operations Reference

| Operation | Purpose | Example Use Case |
|-----------|---------|------------------|
| `create` | Create new resources | Add new blog post, create collection |
| `read` | View/list resources | View blog posts, list schemas |
| `update` | Modify existing resources | Edit blog post, update collection settings |
| `delete` | Remove resources | Delete blog post, remove collection |

## Related Documentation

- [Authentication & Authorization](docs/auth/auth)
- [REST API](docs/api/rest-api)
- [API Keys](docs/api/api-keys)
