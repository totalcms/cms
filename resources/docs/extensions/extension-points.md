---
title: "Extension Points"
description: "All the ways extensions can add functionality to Total CMS: Twig functions, CLI commands, routes, admin pages, field types, events, and more."
since: "3.3.0"
---

# Extension Points

Extensions interact with Total CMS through the `ExtensionContext` object passed to `register()` and `boot()`. This page covers every available extension point.

## Naming Conventions

To avoid collisions with core T3 functions and other extensions, always prefix your Twig functions, filters, and CLI commands with your vendor name:

- Twig functions: `vendor_functionname` (e.g. `acme_seo_title`)
- Twig filters: `vendor_filtername` (e.g. `acme_readinglevel`)
- CLI commands: `vendor:commandname` (e.g. `acme:generate-sitemap`)

If a name collision is detected at boot time, a warning is logged to `extensions.log` and the last registered function/filter wins. Prefixing with your vendor name prevents this.

## Twig Functions

Add custom functions available in all Twig templates.

```php
use Twig\TwigFunction;

public function register(ExtensionContext $context): void
{
    $context->addTwigFunction(
        new TwigFunction('seo_title', function (string $title, ?string $suffix = null): string {
            return $suffix ? "{$title} | {$suffix}" : $title;
        })
    );
}
```

**Permission:** `twig:functions`

Usage in templates:
```twig
<title>{{ seo_title(post.title, 'My Site') }}</title>
```

## Twig Filters

Add custom filters for transforming values in templates.

```php
use Twig\TwigFilter;

public function register(ExtensionContext $context): void
{
    $context->addTwigFilter(
        new TwigFilter('reading_level', function (string $text): string {
            $words = str_word_count($text);
            return match (true) {
                $words < 100 => 'Quick read',
                $words < 500 => 'Medium read',
                default => 'Long read',
            };
        })
    );
}
```

**Permission:** `twig:filters`

Usage in templates:
```twig
<span class="reading-level">{{ post.content|reading_level }}</span>
```

## Twig Globals

Add global variables accessible in all templates.

```php
public function register(ExtensionContext $context): void
{
    $context->addTwigGlobal('seo', new SeoHelper());
}
```

**Permission:** `twig:globals`

Usage in templates:
```twig
{{ seo.metaTags(post) }}
```

## CLI Commands

Register custom commands for the `tcms` CLI tool. Commands must use namespaced names (`vendor:command`).

```php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

public function register(ExtensionContext $context): void
{
    $context->addCommand(new class extends Command {
        protected function configure(): void
        {
            $this->setName('acme:generate-sitemap');
            $this->setDescription('Generate an XML sitemap');
        }

        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            $output->writeln('Sitemap generated.');
            return Command::SUCCESS;
        }
    });
}
```

**Permission:** `cli:commands`

Usage:
```bash
tcms acme:generate-sitemap
```

## Routes (API and Admin)

Register routes under your extension's namespace. Routes are automatically prefixed with `/ext/{vendor}/{name}/`.

```php
use Slim\Routing\RouteCollectorProxy;

public function register(ExtensionContext $context): void
{
    $context->addRoutes(function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', MyDashboardAction::class);
        $group->post('/api/analyze', AnalyzeAction::class);
    });
}
```

**Permissions:** `routes:api`, `routes:admin`

The routes above are accessible at:
- `/ext/acme/seo-pro/dashboard`
- `/ext/acme/seo-pro/api/analyze`

## Admin Navigation

Add items to the admin sidebar.

```php
use TotalCMS\Domain\Extension\Data\AdminNavItem;

public function register(ExtensionContext $context): void
{
    $context->addAdminNavItem(new AdminNavItem(
        label: 'SEO Pro',
        icon: 'seo',
        url: '/ext/acme/seo-pro/dashboard',
        permission: 'admin',
        priority: 50,
    ));
}
```

**Permission:** `admin:nav`

The `priority` field controls ordering (lower numbers appear first). The `permission` field controls visibility (`admin` = admin users only).

## Dashboard Widgets

Add widgets to the admin home screen.

```php
use TotalCMS\Domain\Extension\Data\DashboardWidget;

public function register(ExtensionContext $context): void
{
    $context->addDashboardWidget(new DashboardWidget(
        id: 'seo-score',
        label: 'SEO Score',
        template: 'widgets/seo-score.twig',
        position: 'main',
        priority: 30,
    ));
}
```

**Permission:** `admin:widgets`

The template path is relative to the extension's `templates/` directory. Position is `main` or `sidebar`.

## Custom Field Types

Register new field types for use in collection schemas.

```php
public function register(ExtensionContext $context): void
{
    $context->addFieldType('colorpicker', Acme\Fields\ColorPickerField::class);
}
```

**Permission:** `fields:register`

The class must extend `TotalCMS\Domain\Admin\FormField\FormField`. Once registered, the field type can be used in schemas:

```json
{
    "properties": {
        "accentColor": {
            "type": "colorpicker",
            "label": "Accent Color"
        }
    }
}
```

## Event Listeners

Subscribe to content events. See [Events](events.md) for the full event reference.

```php
public function register(ExtensionContext $context): void
{
    $context->addEventListener('object.created', function (array $payload): void {
        // $payload contains: collection, id
        $this->notifyWebhook($payload);
    });
}
```

**Permission:** `events:listen`

## Container Definitions

Register services in the DI container for dependency injection.

```php
public function register(ExtensionContext $context): void
{
    $context->addContainerDefinition(
        SeoAnalyzer::class,
        fn () => new SeoAnalyzer()
    );
}
```

**Permission:** `container:definitions`

## Settings

Read and write per-extension settings. Settings are stored in `tcms-data/.system/extension-settings/`.

```php
public function boot(ExtensionContext $context): void
{
    $apiKey = $context->setting('api_key', '');
    $allSettings = $context->settings();
}
```

**Permissions:** `settings:read`, `settings:write`

Define a `settings_schema` in your manifest to enable a settings form in the admin UI.

## Service Resolution (Boot Phase)

During `boot()`, resolve any service from the DI container:

```php
public function boot(ExtensionContext $context): void
{
    $config = $context->get(\TotalCMS\Support\Config::class);
    $cache = $context->get(\TotalCMS\Domain\Cache\CacheManager::class);
}
```

Only use `$context->get()` in `boot()`, never in `register()`. The container is not fully built during registration.
