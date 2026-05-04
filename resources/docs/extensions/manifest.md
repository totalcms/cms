---
title: "Extension Manifest Reference"
description: "Complete reference for the extension.json manifest file that every Total CMS extension requires."
since: "3.3.0"
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
    "icon": "icon.svg",
    "requires": {
        "totalcms": ">=3.3.0",
        "php": ">=8.2",
        "extensions": {
            "acme/analytics": ">=1.0.0"
        }
    },
    "min_edition": "standard",
    "entrypoint": "Extension.php",
    "settings_schema": "settings-schema.json",
    "author": {
        "name": "Acme Corp",
        "url": "https://acme.example.com"
    },
    "links": [
        {"label": "Documentation", "url": "https://docs.example.com/seo-pro"},
        {"label": "Dashboard", "url": "/admin/ext/acme/seo-pro/dashboard"}
    ],
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

If the running Total CMS or PHP version doesn't satisfy these constraints, the extension is **listed on the admin Extensions page but cannot be enabled**. A warning panel on the card explains which requirement failed (e.g. "Requires PHP >=8.4 (current: 8.2.10)"), and the Enable button is disabled. The CLI `tcms extension:enable` will exit with the same message. This means users can still see the extension and read its docs/links before upgrading their environment.

Constraint format: a comparison operator (`>=`, `>`, `<=`, `<`, `=`, `!=`) followed by a version. Constraints that don't match this pattern are treated as "no restriction" so a typo doesn't accidentally lock users out.

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

### `icon`

Relative path to an icon image displayed on the extension card in the admin UI. Supports PNG, JPG, GIF, WebP, and SVG formats. The file must be under 64KB. These icons are embedded inline onto the webpage. Please keep them as small as possible.

```json
"icon": "icon.svg"
```

If omitted, the card displays without an icon.

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

### `links`

Card-level links displayed on the extension's tile in the admin Extensions page. Useful for documentation, support, or quick access to admin pages your extension registers.

```json
"links": [
    {"label": "Documentation", "url": "https://docs.example.com/seo-pro"},
    {"label": "Support", "url": "https://example.com/support"},
    {"label": "Dashboard", "url": "/admin/ext/acme/seo-pro/dashboard"}
]
```

Each entry must have a `label` and a `url`. Malformed entries are silently dropped during parsing.

**External vs internal links:**

- URLs starting with `http://` or `https://` are treated as **external** and open in a new tab (`target="_blank"`). They are always shown — including when the extension is disabled or has unmet requirements — so users can read your documentation before deciding to enable.
- All other URLs are treated as **internal** (admin pages your extension registers) and open in the current tab. Internal links are only shown when the extension is **enabled**, since the routes wouldn't resolve otherwise.

If filtering leaves no visible links for the current state, the entire links row is hidden.

### `license`

License identifier (e.g. `MIT`, `proprietary`).

```json
"license": "MIT"
```

## Capabilities

Capabilities describe what an extension does — registering Twig functions, adding admin pages, listening to events, and so on. Unlike traditional plugin systems, **capabilities are not declared in the manifest**. They are auto-detected from the calls your extension makes in its `register()` method (and from filesystem markers like a `schemas/` directory).

When an extension is enabled, the system runs a trial registration to discover what it provides, then writes the detected capabilities to `tcms-data/.system/extensions.json`. Each capability becomes a toggleable **permission** that admins can switch on or off in the admin Extensions page. Toggling a permission off disables that part of the extension's functionality without uninstalling it.

| Capability key | Detection trigger | Description |
|---|---|---|
| `twig:functions` | `$context->addTwigFunction(...)` | Custom Twig functions |
| `twig:filters` | `$context->addTwigFilter(...)` | Custom Twig filters |
| `twig:globals` | `$context->addTwigGlobal(...)` | Twig global variables |
| `cli:commands` | `$context->addCommand(...)` | CLI commands |
| `routes:api` | `$context->addRoutes(...)` | Authenticated API endpoints |
| `routes:public` | `$context->addPublicRoutes(...)` | Unauthenticated public endpoints |
| `routes:admin` | `$context->addAdminRoutes(...)` | Admin pages |
| `admin:nav` | `$context->addAdminNavItem(...)` | Items in the admin sidebar |
| `admin:widgets` | `$context->addDashboardWidget(...)` | Dashboard widgets |
| `admin:assets` | `$context->addAdminAsset(...)` | CSS/JS loaded in the admin |
| `frontend:assets` | `$context->addFrontendAsset(...)` | CSS/JS loaded on public pages |
| `events:listen` | `$context->addEventListener(...)` | Content event subscribers |
| `fields` | `$context->addFieldType(...)` | Custom field types |
| `schemas` | `schemas/` directory exists in the extension | Read-only schemas (Pro+ only) |
| `container` | `$context->addContainerDefinition(...)` | DI container services |

If you add a new capability to an already-enabled extension (for example, you create a `schemas/` directory after the fact), disable + re-enable the extension so the trial registration picks up the new capability and adds it to the permissions list.

For details on each capability and how to register it, see [Extension Points](extension-points.md).
