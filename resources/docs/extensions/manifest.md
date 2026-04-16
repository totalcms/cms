---
title: "Extension Manifest Reference"
description: "Complete reference for the extension.json manifest file that every Total CMS extension requires."
---

# Extension Manifest Reference

Every extension requires an `extension.json` manifest file in its root directory. This file declares the extension's identity, requirements, and capabilities.

## Full Example

```json
{
    "id": "acme/seo-pro",
    "name": "SEO Pro",
    "description": "Advanced SEO tools for Total CMS",
    "version": "1.2.0",
    "requires": {
        "totalcms": ">=3.3.0",
        "php": ">=8.2",
        "extensions": {
            "acme/analytics": ">=1.0.0"
        }
    },
    "permissions": [
        "twig:functions",
        "twig:filters",
        "cli:commands",
        "routes:admin",
        "admin:nav",
        "events:listen",
        "settings:read",
        "settings:write"
    ],
    "min_edition": "standard",
    "entrypoint": "Extension.php",
    "settings_schema": "settings-schema.json",
    "author": {
        "name": "Acme Corp",
        "url": "https://acme.example.com"
    },
    "license": "proprietary"
}
```

## Fields

### `id` (required)

Unique identifier in `vendor/name` format. Must use lowercase alphanumeric characters and hyphens only.

```json
"id": "acme/seo-pro"
```

### `name` (required)

Human-readable name displayed in the admin UI and marketplace.

```json
"name": "SEO Pro"
```

### `version` (required)

Semantic version number (e.g. `1.0.0`, `2.1.3`).

```json
"version": "1.2.0"
```

### `description`

Short description of what the extension does.

```json
"description": "Advanced SEO tools for Total CMS"
```

### `requires`

Version constraints for Total CMS, PHP, and other extensions.

```json
"requires": {
    "totalcms": ">=3.3.0",
    "php": ">=8.2",
    "extensions": {
        "acme/analytics": ">=1.0.0"
    }
}
```

Extensions listed under `extensions` are loaded before this extension (dependency ordering).

### `permissions`

Declares what the extension can do. These are shown to the user before installation. An extension that tries to use a capability not declared in its permissions may be blocked in future versions.

| Permission | Description |
|---|---|
| `twig:functions` | Register custom Twig functions |
| `twig:filters` | Register custom Twig filters |
| `twig:globals` | Register Twig global variables |
| `cli:commands` | Register CLI commands |
| `routes:api` | Register REST API endpoints |
| `routes:admin` | Register admin pages |
| `admin:nav` | Add items to the admin navigation |
| `admin:widgets` | Add dashboard widgets |
| `events:listen` | Subscribe to content events |
| `fields:register` | Register custom field types |
| `settings:read` | Read extension settings |
| `settings:write` | Write extension settings |
| `container:definitions` | Register DI container services |

### `min_edition`

Minimum Total CMS edition required. The extension will not load on lower editions.

| Value | Description |
|---|---|
| `lite` | Available to all editions (default) |
| `standard` | Requires Standard or higher |
| `pro` | Requires Pro or higher |

```json
"min_edition": "pro"
```

### `entrypoint`

Relative path to the PHP file containing the `ExtensionInterface` implementation. Defaults to `Extension.php`.

```json
"entrypoint": "Extension.php"
```

### `settings_schema`

Relative path to a JSON Schema file that defines the extension's settings. Used to render settings forms in the admin UI.

```json
"settings_schema": "settings-schema.json"
```

### `author`

Author information displayed in the admin UI and marketplace.

```json
"author": {
    "name": "Acme Corp",
    "url": "https://acme.example.com"
}
```

### `license`

License identifier (e.g. `MIT`, `proprietary`).

```json
"license": "MIT"
```
