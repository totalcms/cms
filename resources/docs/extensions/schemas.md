---
title: "Extension Schemas"
description: "How extensions can provide collection schemas in Total CMS, including read-only and installable approaches."
since: "3.5.0"
---

# Extension Schemas

Extensions can provide collection schemas in two ways: read-only schemas that live inside the extension, and installable schemas that are copied into the user's data directory.

Both approaches require **Pro edition or higher**.

## Read-Only Schemas

Place JSON schema files in a `schemas/` directory inside your extension. These schemas are automatically discovered and available throughout Total CMS.

```
acme/seo-pro/
    extension.json
    Extension.php
    schemas/
        seo-metadata.json
```

**`schemas/seo-metadata.json`**:
```json
{
    "id": "seo-metadata",
    "description": "SEO metadata for pages",
    "category": "seo",
    "properties": {
        "title": {
            "type": "string",
            "field": "text",
            "label": "SEO Title",
            "maxLength": 60
        },
        "description": {
            "type": "string",
            "field": "text",
            "label": "Meta Description",
            "maxLength": 160
        },
        "canonical": {
            "type": "string",
            "field": "url",
            "label": "Canonical URL"
        }
    },
    "required": ["id", "title"],
    "index": ["id", "title"]
}
```

Read-only schemas:
- Are managed by the extension and stay in sync with extension updates
- Cannot be edited by the user through the admin UI
- Are removed when the extension is disabled or removed
- Appear in the schema list alongside built-in and custom schemas

The presence of a `schemas/` directory is auto-detected when the extension is enabled and surfaces as a `Schemas` capability on the extension's permission list. Admins can toggle this capability off to hide the extension's read-only schemas without uninstalling the extension. If you add a `schemas/` directory to an already-enabled extension, disable + re-enable so the capability is re-detected.

### Lookup Priority

When Total CMS looks up a schema, it checks in this order:

1. **Built-in schemas** (in `resources/schemas/`)
2. **Extension schemas** (in extension `schemas/` directories)
3. **Custom schemas** (in `tcms-data/.schemas/`)

This means an extension schema cannot override a built-in schema, and a custom schema can override an extension schema if needed.

## Installable Schemas

For schemas the user should be able to customize after installation, use `installSchema()` in the `boot()` method:

```php
public function boot(ExtensionContext $context): void
{
    $context->installSchema([
        'id' => 'product-reviews',
        'description' => 'Customer product reviews',
        'category' => 'commerce',
        'properties' => [
            'rating' => [
                'type' => 'number',
                'field' => 'number',
                'label' => 'Rating',
                'min' => 1,
                'max' => 5,
            ],
            'review' => [
                'type' => 'string',
                'field' => 'styledtext',
                'label' => 'Review',
            ],
            'author' => [
                'type' => 'string',
                'field' => 'text',
                'label' => 'Author Name',
            ],
        ],
        'required' => ['id', 'rating', 'review'],
        'index' => ['id', 'rating'],
    ]);
}
```

Installable schemas:
- Are copied into `tcms-data/.schemas/` on first boot
- Are skipped if a schema with the same ID already exists (no overwrites)
- Become user-owned after installation -- the user can edit them freely
- Persist after the extension is removed (the data belongs to the user)

`installSchema()` is gated to **Pro edition or higher**. On lower editions the call returns silently without copying anything, so the same boot code is safe to ship without an explicit edition check.

## When to Use Which

| Approach | Use When |
|---|---|
| **Read-only** (`schemas/` directory) | The schema is integral to the extension and must stay in sync with it |
| **Installable** (`installSchema()`) | The schema is a starting point the user should customize |

Most extensions should use read-only schemas. Use installable schemas only when you explicitly want the user to take ownership.
