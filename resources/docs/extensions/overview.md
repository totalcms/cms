---
title: "Extensions Overview"
description: "Learn how to extend Total CMS with custom functionality using the extension system. Add Twig functions, CLI commands, admin pages, custom schemas, and more."
since: "3.5.0"
related:
  - extensions/manifest
  - extensions/extension-points
  - extensions/events
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

Total CMS also ships **bundled extensions** in `resources/extensions/` — included with every install but disabled by default. They appear in the admin alongside user-installed extensions but cannot be removed (only disabled). See [Bundled Extensions](docs/extensions/bundled).

### Project Extensions

Site-specific extensions can instead live in an `extensions/` directory at the **project root**, next to `tcms-data/`:

```
my-site/
    extensions/
        my-vendor/
            my-extension/
                extension.json
                Extension.php
                ...
    tcms-data/
    public/
```

The directory is purely convention-based — if `extensions/` exists it is scanned, exactly like `tcms-data/extensions/` (same layout, same manifest, same lifecycle). The difference is ownership: `tcms-data` is content (backed up, usually gitignored), while a project extension is **code that belongs in your site's git repo**. Keeping it outside `tcms-data` means committing it requires no gitignore gymnastics, and `git pull` deploys it.

Project extensions can be disabled but not removed from the admin or CLI — they belong to source control, so removal happens there.

### Composer Extensions

On a Composer install, an extension can be a Composer package:

```bash
composer require acme/seo-pro
```

That is the whole install. Total CMS asks Composer which installed packages have the type `totalcms-extension`, reads each one's `extension.json` from its directory under `vendor/`, and loads it like any other extension. Composer autoloads the package's classes, `composer update` moves it to new versions, and `composer remove` takes it out. Nothing is copied into `tcms-data/`.

A Composer extension shows a **Composer** badge in the admin and reports the version Composer installed, not the one in its `extension.json`. It can be disabled but not removed from the admin or CLI, for the same reason as bundled and project extensions: the files belong to Composer. `tcms extension:remove` says so and names the `composer remove` command to run instead.

An update that changes the extension's source is treated like any other extension update: it goes through the same [safety review](docs/extensions/safety), and one that introduces high-risk code patterns is disabled until an operator reviews it.

**Publishing one.** An extension is a Composer package when its `composer.json` says so:

```json
{
    "name": "acme/seo-pro",
    "type": "totalcms-extension",
    "license": "MIT",
    "require": { "php": ">=8.2" },
    "autoload": { "psr-4": { "Acme\\SeoPro\\": "src/" } }
}
```

The `type` is what Total CMS looks for. The `autoload` block replaces the per-extension `vendor/autoload.php` a manually installed copy would carry: on a Composer install the project's own autoloader covers `src/`. The package name and the `id` in `extension.json` may differ; the id is what the admin, the CLI and the state files use. Publish it to Packagist, or to a private repository listed in the site's `composer.json`, and operators install it with `composer require`. The [extension-starter](https://github.com/totalcms/extension-starter) repo already ships this `composer.json` shape.

If the same extension id exists in more than one location, the most specific copy wins: project over Composer over `tcms-data/extensions/` over bundled. A project copy is how a site patches a Composer-distributed extension without forking the package. A log entry is written whenever one copy shadows another.

## Quick Example

A minimal extension that adds a Twig function:

**`extension.json`**:
```json
{
    "id": "acme/hello",
    "name": "Hello Extension",
    "version": "1.0.0",
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

- The exception is caught and logged to `extensions.log` in the [logs directory](docs/operations/filesystem)
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
tcms extension:remove vendor/name      # Remove extension files (tcms-data/extensions only)
```

`extension:list` shows where each extension came from — `bundled`, `composer`, `project` or `user` — and only a `user` extension (one in `tcms-data/extensions/`) can be removed here. The other three are owned by the package, by Composer, or by source control, and are removed there.

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

- [Manifest Reference](docs/extensions/manifest) -- all manifest fields explained
- [Extension Points](docs/extensions/extension-points) -- what extensions can do
- [Events](docs/extensions/events) -- subscribing to content events
- [Schemas](docs/extensions/schemas) -- providing schemas from extensions
