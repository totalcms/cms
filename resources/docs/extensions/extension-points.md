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

**Capability:** `twig:functions`

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

**Capability:** `twig:filters`

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

**Capability:** `twig:globals`

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

**Capability:** `cli:commands`

Usage:
```bash
tcms acme:generate-sitemap
```

## Routes

Extensions can register three types of routes, each with different authentication and URL prefixes.

### API Routes

Register authenticated API routes under `/ext/{vendor}/{name}/`.

```php
use Slim\Routing\RouteCollectorProxy;

public function register(ExtensionContext $context): void
{
    $context->addRoutes(function (RouteCollectorProxy $group): void {
        $group->get('/status', StatusAction::class);
        $group->post('/analyze', AnalyzeAction::class);
    });
}
```

**Capability:** `routes:api`

The routes above are accessible at:
- `/ext/acme/seo-pro/status`
- `/ext/acme/seo-pro/analyze`

### Admin Routes

Register routes under `/admin/ext/{vendor}/{name}/`. These routes are protected by admin authentication middleware. Templates can extend `admin-dashboard.twig` for the admin layout.

```php
use Slim\Routing\RouteCollectorProxy;

public function register(ExtensionContext $context): void
{
    $context->addAdminRoutes(function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', MyDashboardAction::class);
        $group->get('/settings', MySettingsAction::class);
    });
}
```

**Capability:** `routes:admin`

The routes above are accessible at:
- `/admin/ext/acme/seo-pro/dashboard`
- `/admin/ext/acme/seo-pro/settings`

### Public Routes

Register unauthenticated routes under `/ext/{vendor}/{name}/`. These routes have no authentication — use for webhooks, embeds, and endpoints that must be accessible without credentials.

```php
use Slim\Routing\RouteCollectorProxy;

public function register(ExtensionContext $context): void
{
    $context->addPublicRoutes(function (RouteCollectorProxy $group): void {
        $group->post('/webhook', WebhookAction::class);
        $group->get('/embed/{id}', EmbedAction::class);
    });
}
```

**Capability:** `routes:public`

The routes above are accessible at:
- `/ext/acme/seo-pro/webhook`
- `/ext/acme/seo-pro/embed/{id}`

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

**Capability:** `admin:nav`

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

**Capability:** `admin:widgets`

The template path is relative to the extension's `templates/` directory. Position is `main` or `sidebar`.

## Custom Field Types

Register new field types for use in collection schemas.

```php
public function register(ExtensionContext $context): void
{
    $context->addFieldType('colorpicker', Acme\Fields\ColorPickerField::class);
}
```

**Capability:** `fields`

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

**Capability:** `events:listen`

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

**Capability:** `container`

## Admin Assets

Load custom CSS or JavaScript files in the admin interface.

```php
public function register(ExtensionContext $context): void
{
    $context->addAdminAsset('css', 'styles/admin.css');
    $context->addAdminAsset('js', 'scripts/admin.js');
}
```

**Capability:** `admin:assets`

The path is relative to the extension's `assets/` directory. For example, the CSS file above would be at `tcms-data/extensions/acme/seo-pro/assets/styles/admin.css`.

## Settings

Read per-extension settings. Settings are stored in `tcms-data/.system/extension-settings/`.

```php
public function boot(ExtensionContext $context): void
{
    $apiKey = $context->setting('api_key', '');
    $allSettings = $context->settings();
}
```

Settings access is not a separate capability — `$context->setting()` and `$context->settings()` are always available regardless of permission state.

Define a `settings_schema` in your manifest to enable a settings form in the admin UI. Settings are managed by admins through the extension settings page in the dashboard.

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
