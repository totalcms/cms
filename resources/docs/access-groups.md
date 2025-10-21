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
            "methods": ["GET", "POST", "PUT", "DELETE"],
            "permissions": {
                "collections": {
                    "methods": ["GET", "POST", "PUT", "DELETE"],
                    "all": false,
                    "allowed": ["blog", "news"]
                },
                "schemas": {
                    "methods": ["GET"],
                    "all": false,
                    "allowed": ["blog"]
                },
                "templates": false,
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

### Permission Types

#### Resource-Based Permissions (Collections, Schemas)

```json
"collections": {
    "methods": ["GET", "POST", "PUT", "DELETE"],
    "all": true,              // Access all collections
    "allowed": []             // Or specific collection IDs
}
```

- `methods`: HTTP methods allowed on this resource type
- `all`: If `true`, access all resources; if `false`, only access items in `allowed`
- `allowed`: Array of specific resource IDs (e.g., collection names, schema names)

#### Section-Based Permissions (Settings, Utils)

```json
"settings": {
    "methods": ["GET", "POST"],
    "all": false,
    "allowed": ["general", "cache", "mailer"]
}
```

- `methods`: HTTP methods allowed
- `all`: If `true`, access all sections/pages
- `allowed`: Array of specific section/page names

#### Boolean Permissions (Templates, Mailer, Playground, Docs)

```json
"templates": true,    // Full access to templates
"mailer": false,      // No access to mailer
"playground": true,   // Access to playground
"docs": true          // Access to documentation
```

Simple `true`/`false` for features that don't have granular permissions.

## Twig Helper Functions

Total CMS provides helper functions to check permissions in your templates, allowing you to hide/show UI elements based on the current user's access groups.

### Collections

**Check specific collection access:**
```twig
{% if cms.canAccessCollection('blog', 'GET') %}
    <a href="collections/blog">View Blog Posts</a>
{% endif %}

{% if cms.canAccessCollection('blog', 'POST') %}
    <button>New Post</button>
{% endif %}
```

**Check general collections access (no specific collection):**
```twig
{% if cms.canAccessCollectionsMethod('GET') %}
    <a href="collections">View Collections</a>
{% endif %}

{% if cms.canAccessCollectionsMethod('POST') %}
    <button>New Collection</button>
{% endif %}
```

**Get list of accessible collections:**
```twig
{% for collection in cms.getAccessibleCollections() %}
    <li>{{ collection.id }}</li>
{% endfor %}
```

### Schemas

**Check specific schema access:**
```twig
{% if cms.canAccessSchema('blog', 'GET') %}
    <a href="schemas/blog">View Blog Schema</a>
{% endif %}

{% if cms.canAccessSchema('blog', 'PUT') %}
    <button>Edit Schema</button>
{% endif %}
```

**Check general schemas access:**
```twig
{% if cms.canAccessSchemasMethod('GET') %}
    <a href="schemas">View Schemas</a>
{% endif %}

{% if cms.canAccessSchemasMethod('POST') %}
    <button>New Schema</button>
{% endif %}
```

### Templates

**Check templates access:**
```twig
{% if cms.canAccessTemplatesMethod('GET') %}
    <a href="templates">View Templates</a>
{% endif %}

{% if cms.canAccessTemplatesMethod('POST') %}
    <button>New Template</button>
{% endif %}

{% if cms.canAccessTemplatesMethod('DELETE') %}
    <button class="delete">Delete Template</button>
{% endif %}
```

### Settings

**Check specific settings section:**
```twig
{% if cms.canAccessSetting('cache', 'GET') %}
    <a href="settings/cache">Cache Settings</a>
{% endif %}

{% if cms.canAccessSetting('general', 'POST') %}
    <button>Save Settings</button>
{% endif %}
```

**Check general settings access:**
```twig
{% if cms.canAccessSettingsMethod('GET') %}
    <a href="settings">Settings</a>
{% endif %}
```

### Utils

**Check specific utils page:**
```twig
{% if cms.canAccessUtil('cache-manager', 'GET') %}
    <a href="utils/cache-manager">Cache Manager</a>
{% endif %}

{% if cms.canAccessUtil('jumpstart', 'POST') %}
    <button>Import JumpStart</button>
{% endif %}
```

**Check general utils access:**
```twig
{% if cms.canAccessUtilsMethod('GET') %}
    <a href="utils">Utils</a>
{% endif %}
```

### Boolean Permissions

**Check mailer access:**
```twig
{% if cms.canAccessMailer() %}
    <a href="mailer">Mailer</a>
{% endif %}
```

**Check playground access:**
```twig
{% if cms.canAccessPlayground() %}
    <a href="playground">Playground</a>
{% endif %}
```

**Check docs access:**
```twig
{% if cms.canAccessDocs() %}
    <a href="docs">Documentation</a>
{% endif %}
```

### Admin Check

**Check if user is super admin:**
```twig
{% if cms.isAdmin() %}
    <div class="admin-only-feature">
        <a href="utils/access-groups">Manage Access Groups</a>
    </div>
{% endif %}
```

Super admins bypass all access checks and have full access to everything.

## Practical Examples

### Conditional Navigation Menu

```twig
<nav>
    {% if cms.canAccessCollectionsMethod('GET') %}
    <a href="collections">Collections</a>
    {% endif %}

    {% if cms.canAccessSchemasMethod('GET') %}
    <a href="schemas">Schemas</a>
    {% endif %}

    {% if cms.canAccessTemplatesMethod('GET') %}
    <a href="templates">Templates</a>
    {% endif %}

    {% if cms.canAccessSettingsMethod('GET') %}
    <a href="settings">Settings</a>
    {% endif %}

    {% if cms.isAdmin() %}
    <a href="utils/access-groups">Access Groups</a>
    {% endif %}
</nav>
```

### Filtered Collection List

```twig
<ul>
{% for collection in collections %}
    {% if cms.canAccessCollection(collection.id, 'GET') %}
    <li>
        <a href="collections/{{ collection.id }}">{{ collection.label }}</a>
        {% if cms.canAccessCollection(collection.id, 'POST') %}
        <button data-collection="{{ collection.id }}">New Item</button>
        {% endif %}
    </li>
    {% endif %}
{% endfor %}
</ul>
```

### Conditional Form Buttons

```twig
{{ cms.form.builder(collection, {
    save: cms.canAccessCollection(collection, 'PUT') ? "Save" : false,
    delete: cms.canAccessCollection(collection, 'DELETE') ? "Delete" : false
}) }}
```

### Settings Section Filter

```twig
{% set sections = ['general', 'cache', 'auth', 'mailer'] %}
<ul>
{% for section in sections %}
    {% if cms.canAccessSetting(section, 'GET') %}
    <li><a href="settings/{{ section }}">{{ section|title }}</a></li>
    {% endif %}
{% endfor %}
</ul>
```

## Best Practices

1. **Use UI Controls Everywhere**: Always check permissions before showing links, buttons, or forms. Don't rely on middleware alone - good UX means hiding what users can't access.

2. **Check Appropriate Methods**: Use the appropriate HTTP method for the action:
   - `GET` for viewing/listing
   - `POST` for creating
   - `PUT` for updating
   - `DELETE` for deleting

3. **Graceful Degradation**: Hide buttons/actions users can't perform rather than showing disabled buttons.

4. **Admin-Only Features**: Use `cms.isAdmin()` for features that should never be delegated (like managing access groups, API keys, or user accounts).

5. **Method-Level Checks**: Use general method checks (e.g., `canAccessCollectionsMethod()`) for listing pages where no specific resource is selected yet.

## Admin-Only Routes

Some routes require super admin access and cannot be delegated via access groups:

- **Access Groups Management**: `/admin/utils/access-groups`, `/access-groups/*`
- **API Key Management**: `/apikeys/*`
- **User Management**: `/users/*` (if implemented)

These routes use `AdminOnlyMiddleware` which only allows super admin users.

## HTTP Methods Reference

- **GET**: View/read data
- **POST**: Create new resources
- **PUT**: Update existing resources
- **DELETE**: Delete resources
- **PATCH**: Partial updates (rarely used)

## Related Documentation

- [Authentication & Authorization](docs/auth)
- [REST API](docs/rest-api)
- [API Keys](docs/api-keys)
- [Access Group Testing Guide](docs/access-group-testing-guide)
