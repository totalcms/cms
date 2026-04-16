---
title: "Extensions Overview"
description: "Learn how to extend Total CMS with custom functionality using the extension system. Add Twig functions, CLI commands, admin pages, custom schemas, and more."
since: "3.3.0"
---

# Extensions Overview

The Total CMS extension system lets you add custom functionality without modifying core files. Extensions can add Twig functions and filters, CLI commands, admin pages, REST API endpoints, custom field types, event listeners, dashboard widgets, and schemas.

## How Extensions Work

Extensions follow a two-phase lifecycle:

1. **Register** -- declare what your extension provides (Twig functions, CLI commands, etc.)
2. **Boot** -- wire into the running application (subscribe to events, resolve services)

Every extension implements the `ExtensionInterface` and interacts with Total CMS through an `ExtensionContext` object that provides a stable, versioned API.

## Directory Structure

Extensions live in `tcms-data/extensions/{vendor}/{extension-name}/`:

```
tcms-data/extensions/
    acme/
        seo-pro/
            extension.json      # Manifest
            Extension.php       # Entry point (implements ExtensionInterface)
            src/                # Additional PHP classes
            schemas/            # Read-only schemas (Pro+ only)
            templates/          # Twig templates
            assets/             # CSS, JS, images
```

## Quick Example

A minimal extension that adds a Twig function:

**`extension.json`**:
```json
{
    "id": "acme/hello",
    "name": "Hello Extension",
    "version": "1.0.0",
    "permissions": ["twig:functions"],
    "entrypoint": "Extension.php",
    "license": "MIT"
}
```

**`Extension.php`**:
```php
<?php

namespace Acme\Hello;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use Twig\TwigFunction;

class Extension implements ExtensionInterface
{
    public function register(ExtensionContext $context): void
    {
        $context->addTwigFunction(
            new TwigFunction('hello', fn (string $name): string => "Hello, {$name}!")
        );
    }

    public function boot(ExtensionContext $context): void
    {
        // Nothing to do on boot for this simple extension
    }
}
```

Use it in a template:

```twig
{{ hello('World') }}
{# Output: Hello, World! #}
```

## Edition Requirements

Extensions can declare a minimum edition in their manifest:

```json
{
    "min_edition": "pro"
}
```

Valid values: `lite` (default), `standard`, `pro`. Extensions that require a higher edition than the site's license are not loaded.

Extension-provided schemas always require Pro or higher, regardless of the `min_edition` setting.

## Fault Isolation

A broken extension cannot crash Total CMS. If an extension throws an exception during `register()` or `boot()`:

- The exception is caught and logged to `logs/extensions.log`
- The error is recorded in the extension's state (visible in the admin UI)
- Other extensions continue loading normally
- The site operates without the broken extension

## Managing Extensions

**Admin UI:** Navigate to Settings > Extensions to see all installed extensions, enable/disable them, and view errors.

**CLI:**
```bash
tcms extension:list                    # List all extensions
tcms extension:enable vendor/name      # Enable an extension
tcms extension:disable vendor/name     # Disable an extension
tcms extension:remove vendor/name      # Remove extension files
```

## Starter Template

Clone the [extension-starter](https://github.com/totalcms/extension-starter) repo to get a working extension with examples of every extension point:

```bash
cd tcms-data/extensions/
mkdir your-vendor && cd your-vendor
git clone https://github.com/totalcms/extension-starter.git your-extension
cd your-extension && composer install
tcms extension:enable your-vendor/your-extension
```

## Next Steps

- [Manifest Reference](manifest.md) -- all manifest fields explained
- [Extension Points](extension-points.md) -- what extensions can do
- [Events](events.md) -- subscribing to content events
- [Schemas](schemas.md) -- providing schemas from extensions
