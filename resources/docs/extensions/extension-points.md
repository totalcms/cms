---
title: "Extension Points"
description: "All the ways extensions can add functionality to Total CMS: Twig functions, CLI commands, routes, admin pages, field types, events, and more."
since: "3.5.0"
---

# Extension Points

Extensions interact with Total CMS through the `ExtensionContext` object passed to `register()` and `boot()`. This page covers every available extension point.

## Naming Conventions

To avoid collisions with core T3 functions and other extensions, always prefix your Twig functions, filters, and CLI commands with your vendor name:

- Twig functions: `vendor_functionname` (e.g. `acme_seo_title`)
- Twig filters: `vendor_filtername` (e.g. `acme_readinglevel`)
- CLI commands: `vendor:commandname` (e.g. `acme:generate-sitemap`)

If a name collision is detected at boot time, a warning is logged to `extensions.log` and the last registered function/filter wins. Prefixing with your vendor name prevents this.

## Twig rendering model

Total CMS renders templates in **streaming (yield) mode** — the same mode Twig 4 uses exclusively. This is transparent to extensions, with one rule: your Twig functions and filters must **return** their output. Never `echo`, `print`, or open an output buffer (`ob_start()`) inside a function or filter — in streaming mode anything written directly to output bypasses the template stream and lands in the wrong place (or is lost entirely). Build a string and return it.

```php
// ✅ Correct — return the value; Twig places it in the stream
new TwigFunction('acme_banner', fn (): string => '<div class="banner">…</div>');

// ❌ Wrong — echo bypasses the render stream under yield mode
new TwigFunction('acme_banner', function (): void {
    echo '<div class="banner">…</div>';
});
```

Extensions register Twig **functions, filters, and globals** — never custom tags or nodes — so there is nothing else to do to be yield-ready.

> **Security: Twig autoescaping is OFF.** Total CMS renders content fields as trusted author HTML/Markdown, so Twig does not escape output for you. Any value your extension prints that came from outside the admin — request input, anonymous submissions, third-party APIs — must be escaped explicitly with `{{ value | e }}` (or `| e('html_attr')` inside an attribute), or wrapped in `{% autoescape 'html' %}`. A handler that prints `{{ input }}` has no automatic XSS protection. See [Template Output Escaping](operations/security#template-output-escaping-twig).

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

Register routes under `/admin/ext/{vendor}/{name}/`. These routes require a logged-in dashboard user and are **super-admin only by default**. Templates can extend `admin-dashboard.twig` for the admin layout.

```php
use Slim\Routing\RouteCollectorProxy;

public function register(ExtensionContext $context): void
{
    $context->addAdminRoutes(function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', MyDashboardAction::class);
        $group->get('/settings', MySettingsAction::class);

        // Open a page to every logged-in dashboard user (not just admins)
        $group->get('/reports', MyReportsAction::class, permission: 'any');
    });
}
```

**Capability:** `routes:admin`

The routes above are accessible at:
- `/admin/ext/acme/seo-pro/dashboard`
- `/admin/ext/acme/seo-pro/settings`

Pass `permission: 'any'` per route to open a page to non-admin dashboard users; anything else means admin-only. Set the same value on the [admin navigation item](#admin-navigation) that links to the page — the nav `permission` controls who *sees the link*, the route `permission` controls who *can load the page*.

Operators have the final say over `'any'` surfaces: each access group has an **Extension Access** list (Utilities → Access Groups) that controls which extensions' nav items, widgets, and `'any'` pages its members can see and open. Groups default to all-extensions-granted; `admin` pages are unaffected (super admins only, always).

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

### Route placeholders

API, public, and admin routes all support Slim-style `{placeholder}` segments. A captured value is passed to the handler in its `$args` array, keyed by the placeholder name:

```php
$group->get('/embed/{id}', function ($request, $response, array $args) {
    $id = $args['id'];   // 'abc' for /ext/acme/seo-pro/embed/abc
    // ...
    return $response;
});
```

`{id}` matches a single path segment; add a regex constraint with `{id:\d+}` to restrict it (e.g. digits only). An exact static route (`/embed/list`) always wins over a placeholder route (`/embed/{id}`) on the same path, regardless of registration order.

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
    $context->addFieldType('colorpicker', Acme\Fields\ColorPickerField::class, 'color');
}
```

**Capability:** `fields`

The class must extend `TotalCMS\Domain\Admin\FormField\FormField`. The third
argument declares the default schema property type used when an author leaves
the property's `type` blank — should be one of `SchemaData::PROPERTY_TYPES`
(e.g. `string`, `color`, `array`). Defaults to `string` if omitted.

Once registered, the field type can be used in schemas:

```json
{
    "properties": {
        "accentColor": {
            "field": "colorpicker"
        }
    }
}
```

## Event Listeners

Subscribe to content events. See [Events](docs/extensions/events) for the full event reference.

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

## Automations

Ship a server-side [automation](docs/automations/overview) as part of your extension. Like the user-authored automations in the admin, an extension automation runs on a **schedule** or a content **event** — but its handler is the closure you register here, held in memory (never written to disk). The handler receives an `AutomationContext` with pre-injected services (`objectFetcher`, `objectSaver`, `objectUpdater`, `objectRemover`, `indexReader`, `mailer`, `config`, `logger`) plus the run payload (`trigger`, `args`, `event`).

```php
public function register(ExtensionContext $context): void
{
    $context->addAutomation(
        id: 'prune-drafts',
        label: 'Prune stale drafts',
        triggers: [
            ['type' => 'schedule', 'cron' => '0 3 * * *'],                 // daily at 03:00
            // ['type' => 'event', 'event' => 'object.created', 'collection' => 'members'],
        ],
        handler: function (\TotalCMS\Domain\Automation\Data\AutomationContext $ctx): array {
            $ctx->logger->info('[acme/starter] prune-drafts tick');
            // ... do work via $ctx->objectFetcher / $ctx->objectRemover ...

            return ['pruned' => 0];
        },
    );
}
```

Extension automations appear in the automations admin **read-only**, grouped under your extension's id in the sidebar and listed with their triggers, last run, and consecutive-failure count on the Automations landing page. Operators can **Run now** and reset a failure count there, but cannot edit the closure, since it lives in your code — there is no code field to show. To switch a whole extension's automations off, they use the `automations` capability toggle on your extension's settings page; a forbidden automation still appears, marked *permission off*, rather than vanishing. Returning a value records it on the run record; throwing marks the run failed.

Note that the failure guard applies to your automations too: five consecutive failures on Production auto-disables one, so a handler that throws every tick will stop being scheduled. The failure count and the reset button are how an operator sees and recovers from that.

For **HTTP triggers**, register a [public route](#public-routes) rather than a `webhook` trigger — an extension automation's id (`vendor/name:id`) isn't a URL path, so webhook triggers stay reserved for user-authored automations.

**Capability:** `automations`

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

Register services under your own vendor namespace. Core service IDs cannot be overridden — a definition whose ID belongs to Total CMS (the `TotalCMS\` namespace or any service the core container already defines) is skipped with a warning in `extensions.log`. The rest of your extension still loads.

**Capability:** `container` — always-on infrastructure: it is applied whenever the extension is enabled and never appears as a permission toggle, since your other capabilities (routes, page middleware, Twig functions) resolve against these services.

## Page Middleware

Register middleware that builder pages can opt into via their `middleware` field. Useful for auth gates, rate limits, geo redirects, A/B splits, or anything else that needs to run before a page renders. The middleware can short-circuit (return a response — auth redirect, 429, etc.) or pass through (return null) and let the page render.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;

class GeoRedirect implements PageMiddlewareInterface
{
    public function __construct(private readonly GeoIPService $geo) {}

    public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
    {
        if ($this->geo->countryFor($request) === 'EU') {
            return (new \Nyholm\Psr7\Factory\Psr17Factory())
                ->createResponse(302)
                ->withHeader('Location', '/eu' . $request->getUri()->getPath());
        }

        return null;
    }
}
```

```php
public function register(ExtensionContext $context): void
{
    $context->addContainerDefinition(GeoRedirect::class, fn ($c) => new GeoRedirect(
        $c->get(GeoIPService::class),
    ));
    $context->addPageMiddleware('geo-redirect', GeoRedirect::class);
}
```

Once registered, `geo-redirect` shows up in the page form's middleware multiselect. Admins choose which pages use it; the runner invokes it in the order listed on each page.

**Naming**: lowercase letters, digits, hyphens (e.g. `geo-redirect`, `staff-only`, `rate-limit`). Names are stable contract — once shipped, a rename will break sites that have it in their page records.

**Failure modes**:
- An unknown name in a page's middleware list (typo, uninstalled extension) is logged and silently skipped.
- A middleware that throws causes the runner to return a 500 — fail-closed for security.

**Capability:** `page-middleware`

See the [Page Middleware section in the Builder overview](docs/site-builder/overview#page-features-middleware) for the user-facing perspective.

## Form Actions

Register custom form action types that fire after a form save. The JavaScript form processor dispatches the action to an extension-owned API route — no core JS changes needed per provider.

```php
use TotalCMS\Domain\Extension\Data\FormAction;

public function register(ExtensionContext $context): void
{
    // Register the action type — tells core "slack" is a valid form action
    $context->addFormAction('slack', new FormAction(
        name: 'slack',
        route: '/ext/acme/slack-notify/send',
        label: 'Slack Notification',
    ));

    // Register the API route that handles the action
    $context->addRoutes(function ($group): void {
        $group->post('/send', SlackNotifyAction::class);
    });
}
```

**Capability:** `form-actions`

**Edition requirement:** Extension form actions require the Pro edition. On lower editions, actions matching extension-registered types are silently filtered from the form's action list.

The action handler receives a POST with `{ data: <formData>, ...actionConfig }` — the form data plus all properties from the action's JSON config. For example, a collection's `.meta.json` might define:

```json
{
    "formSettings": {
        "newActions": [
            {
                "action": "slack",
                "channel": "#orders",
                "message": "New order from {{ data.name }}"
            }
        ]
    }
}
```

The handler receives `data` (the saved form fields), `channel`, `message`, and any other properties the operator configured. Parse what you need, ignore the rest.

**Naming:** Use your vendor name or a distinctive slug (e.g. `slack`, `discord`, `ntfy`). The name appears in collection action configs and must be stable once shipped.

See the bundled [Pushover extension](docs/extensions/pushover) for a complete working example.

## Assets (CSS / JS)

Extensions can register CSS or JavaScript files for the **admin interface** and/or **public pages**. Each surface has its own registration method and capability:

```php
public function register(ExtensionContext $context): void
{
    // Admin interface only
    $context->addAdminAsset('css', 'styles/admin.css');
    $context->addAdminAsset('js', 'scripts/admin.js');

    // Public pages only
    $context->addFrontendAsset('css', 'styles/widget.css');
    $context->addFrontendAsset('js', 'scripts/widget.js');
}
```

**Capabilities:** `admin:assets`, `frontend:assets`

Paths are relative to the extension's `assets/` directory. For example, the CSS file above would resolve to `tcms-data/extensions/acme/seo-pro/assets/styles/admin.css` and be served from `/ext/acme/seo-pro/assets/styles/admin.css` with an `mtime`-based cache-busting query string.

### Optional parameters

Both methods accept the same set of options:

```php
$context->addFrontendAsset(
    type: 'js',
    path: 'scripts/widget.js',
    position: 'body',   // 'head' | 'body' | null — null uses the default
    module: true,       // load as <script type="module"> (default true)
    preload: true,      // emit a <link rel="modulepreload"> hint in the head
    version: null,      // override the cache-bust query string (default: file mtime)
);
```

Defaults: CSS goes in the head, JS goes in the body, module scripts emit `type="module"`, no preload, mtime-based cache busting.

### Where they render

Extension assets are merged with Total CMS core assets and emitted by these Twig helpers in your templates:

| Surface | Helper | Typical placement |
|---|---|---|
| Public pages | `{{ cms.assetsHead() }}` | inside `<head>` |
| Public pages | `{{ cms.assetsBody() }}` | just before `</body>` |
| Admin pages  | `{{ cms.adminAssetsHead() }}` | inside `<head>` (already wired by core admin templates) |
| Admin pages  | `{{ cms.adminAssetsBody() }}` | just before `</body>` (already wired) |

For the admin interface there's nothing to do — core admin templates already call the helpers. For public pages, your theme template needs to call `cms.assetsHead()` / `cms.assetsBody()` for extension frontend assets to render.

Within each helper, output ordering is:
1. Stylesheets first.
2. Preload hints (`<link rel="preload">` / `<link rel="modulepreload">`) — always emitted in the head regardless of the asset's own `position`.
3. Script tags last.

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

## File Storage

When your extension needs to persist files — generated secrets, caches, state — use the storage API instead of raw file functions:

```php
public function register(ExtensionContext $context): void
{
    $storage = $context->storage();

    $storage->write('state.json', json_encode($state));   // creates directories as needed
    $data   = $storage->read('state.json');               // null when missing
    $exists = $storage->exists('state.json');
    $storage->delete('state.json');
    $path   = $storage->path('state.json');               // absolute path, e.g. for streaming
}
```

Files land in `tcms-data/.system/extension-data/{vendor}/{name}/` — protected behind the web server's deny rules, excluded from version control, and **safe across application updates**. Directories are created `0700` and files `0600`. Paths are relative to your extension's directory; absolute paths and `..` traversal throw.

`write()` throws when the datadir isn't writable — let it propagate from `register()`/`boot()` so the failure is recorded as a visible extension error instead of a silent no-op.

Prefer this over `file_put_contents()`: raw file writes in your source are flagged on the pre-enable review screen (they're unconstrained — the operator deserves a heads-up), while storage API calls are not. Storage access is not a separate capability — like settings, it is always available.

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

## Logging

Extensions can write to the shared `extensions.log` file (`tcms-data/.system/logs/extensions.log`) using the same logger Total CMS uses internally. Get it from the context with `$context->logger()` — it returns a `Psr\Log\LoggerInterface`.

```php
public function register(ExtensionContext $context): void
{
    $logger = $context->logger();

    $context->addEventListener('object.created', function (array $payload) use ($logger): void {
        $logger->info('[acme/starter] object.created', $payload);
    });
}

public function boot(ExtensionContext $context): void
{
    $context->logger()->debug('[acme/starter] booted');
}
```

PSR-3 levels are available: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`. Pass a context array as the second argument for structured fields — Monolog appends them to the formatted line.

Logger access is not a separate capability — `$context->logger()` is always available, in both `register()` and `boot()`.

Prefix log messages with your extension id (e.g. `[acme/starter]`) so multi-extension logs stay readable. All extensions share the `extensions` channel and the same rotating log file.

## MCP Prompts

Register prompts that the MCP server exposes to AI agents via `prompts/list` and `prompts/get`. Use when the extension ships prompts as PHP code rather than relying on operator-authored objects in the `mcp-prompt` collection.

```php
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;

public function register(ExtensionContext $context): void
{
    $context->registerMcpPrompt(
        new Prompt(
            name: 'acme_audit_links',
            description: 'Audit broken links on any page.',
            arguments: [
                new \Mcp\Schema\PromptArgument('url', 'The URL to audit', required: true),
            ],
        ),
        handler: fn (array $arguments = []): array => [
            new PromptMessage(
                Role::User,
                new TextContent('Check all links on: ' . ($arguments['url'] ?? '')),
            ),
        ],
        access: 'admin',
    );
}
```

**Capability:** `mcp:prompts`

**Access:** The optional third argument `$access` sets visibility — `'admin'` (default), `'authenticated'`, or `'public'`. The default keeps prompts private to admin-persona callers. Choose `'public'` only when the prompt body contains no site-private data.

**Collision policy:** If a prompt name collides with a collection-stored prompt, the collection-stored version wins — the extension's prompt is logged and skipped.

## Search Providers

Register a custom search provider that replaces or supplements T3's built-in text search. The provider handles indexing (on object create/update/delete events) and querying (from MCP search tools, future REST endpoints, or site-wide search).

```php
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Data\SearchResult;

class MySearchProvider implements SearchProvider
{
    public function id(): string { return 'my-search'; }
    public function label(): string { return 'My Search'; }

    public function search(SearchQuery $query): array
    {
        // Query your search backend, return list<SearchResult>
        return [];
    }

    public function index(string $collection, string $id, array $data): void
    {
        // Index one object in your backend
    }

    public function delete(string $collection, string $id): void
    {
        // Remove one object from your backend
    }

    public function isAvailable(): bool
    {
        // Fast health check — cache with short TTL (on the hot path)
        return true;
    }
}
```

```php
public function register(ExtensionContext $context): void
{
    $context->registerSearchProvider(new MySearchProvider());
}
```

**Capability:** `mcp:search`

Provider ids must be unique across all extensions + the built-in `text` provider. The registrar logs and skips collisions during boot. When `isAvailable()` returns false, SearchService silently falls back to text search. Throwing from `search()` also triggers the fallback. Throwing from `index()` or `delete()` enqueues a retry job.

See the bundled [Algolia Search extension](docs/extensions/algolia-search) for a complete working example.
