# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Total CMS is a modern PHP-based Content Management System using flat-file JSON storage. Built with Slim 4 framework, it provides a RESTful API with Twig templating and a comprehensive admin interface. The product is in production with 200+ sites. Version 3.5 (shipped 2026-08-26) added the CLI, extension system, event system, Composer distribution, Site Builder, public registration, platform-installation flow, built-in MCP server, and Automations. Active development is on `develop` toward 3.6.

### Related Projects
- **Total CMS License API** ([totalcms/license.totalcms.co](https://github.com/totalcms/license.totalcms.co)): License validation and trial management with similar Slim 4 architecture
- **Total CMS 3 Stacks**: Stacks plugin for the Stacks platform
- **Documentation Site** ([totalcms/docs.totalcms.co](https://github.com/totalcms/docs.totalcms.co)): Public docs at docs.totalcms.co (Astro Starlight). Source of truth is `/resources/docs/` in this repo; synced to the docs site via the build script
- **Marketing Site**: totalcms.co — built with Total CMS itself (Site Builder), so it doubles as the dogfood install (local: `~/Websites/totalcms.co`)
- **Extension Starter** ([totalcms/extension-starter](https://github.com/totalcms/extension-starter)): Template repo for building T3 extensions, demonstrates every extension point
- **Official Docs Connector**: `https://totalcms.co/mcp` — the marketing site's own MCP server serves the T3 documentation to AI agents (`docs_search`, `docs_get`, `docs_lookup`) via a private extension. Successor to the retired standalone mcp.totalcms.co app.
- **Project Repo** ([totalcms/totalcms-project](https://github.com/totalcms/totalcms-project)): Composer project template for installing T3 via `composer create-project`

## Technology Stack

- **Backend**: PHP 8.2+, Slim 4, Twig 3, PHP-DI 7, PSR-7/PSR-15
- **Frontend**: ESBuild, Sass/SCSS, TypeScript/ES6+, HTMX 4.x, Node.js/Yarn
- **Rich Text**: Tiptap editor (replaced Froala)
- **Search**: `SearchProvider` abstraction — built-in `text` provider by default, optional Algolia (`algolia/algoliasearch-client-php`)
- **Testing**: Pest (PHP testing), PHPStan Level 8, PHP-CS-Fixer, PHPMD, Rector

## Common Development Commands

### Build and Development

You do not need to worry about frontend asset building (primary development command).
There is a watch script in dev that will autobuild all front end assets.

```bash
# Development with file watching (typically runs in background)
bin/watch.sh

# Full application build (manual release builds only)
composer run build

# Create distribution bundle (manual release builds only)
composer run bundle
```

### Code Quality and Testing
```bash
# Static analysis (PHPStan Level 8)
composer run stan

# Code style checking and fixing
composer run cs
composer run cs:fix

# Run tests with Pest
composer run test
composer run test:filter -- --filter=SomeTest   # single test
composer run test:quick                          # fast subset
composer run test:parallel                       # parallel runner

# Verify the bundle integrity manifest is current (~0.3s).
# Run this after editing anything under config/, resources/templates/,
# or the covered src/ files — a stale manifest breaks writes at runtime
# with 400 "installation has been corrupted", and the test suite does NOT
# catch it. See "Bundle integrity" under Testing Best Practices.
composer run bundle:check

# Run all quality checks
composer run test:all
```

`composer.json` defines ~40 scripts; the others worth knowing are `quality` /
`quality:full`, `docs:validate`, `rector` / `rector:fix`, and `mcp:inspect`.

## Architecture Overview

### Directory Structure
- **`/src/Action/`** - HTTP action handlers organized by domain (Admin, Auth, Collection, Extension, Upload, etc.)
- **`/src/Domain/`** - Business logic layer with services, repositories, and data objects
  - **`/src/Domain/Extension/`** - Extension system: discovery, lifecycle, permissions, settings, route collection
  - **`/src/Domain/Event/`** - Core event dispatcher (used by extensions and internal services)
  - **`/src/Domain/Automation/`** - Automations: schedule/webhook/event triggers, queue, runner, state store
  - **`/src/Domain/DataView/`** - Data Views: saved cross-collection queries (Pro+ edition feature)
  - **`/src/Domain/Search/`** - Search provider abstraction, indexing jobs, reindex listeners
  - **`/src/Domain/XmlRpc/`** - WordPress-compatible XML-RPC publishing endpoint (off by default)
  - **`/src/Domain/JumpStart/`** - JumpStart data import/export system
  - **`/src/Domain/Import/`** - CMS import systems (Alloy, Total CMS 1, Wordpress, CSV, JSON, RSS, URL)
  - **`/src/Domain/Factory/`** - Factory system for generating test data using Faker
  - **`/src/Domain/ImageWorks/`** - Image processing with watermarking, font management, and caching
  - **`/src/Domain/Twig/`** - Twig templating system with adapters, extensions, and custom functions
  - **`/src/Domain/Mailer/`** - Transactional + bulk mailer (bulk is edition-gated)
  - **`/src/Domain/AccessGroup/`** + **`/src/Domain/ApiKey/`** + **`/src/Domain/OAuth/`** - Authorization credentials and grouping
  - **`/src/Domain/Sync/`** - `tcms push` / `tcms pull` collection sync (5-collection allowlist)
  - **`/src/Domain/Visualizer/`** - Collection + object relationship diagrams (Mermaid)
  - *(53 domains total — the above are the high-traffic ones; browse `src/Domain/` for the rest)*
- **`/src/CLI/`** - Symfony Console CLI application and commands
- **`/src/Middleware/`** - HTTP middleware for auth, CORS, licensing, edition gating, validation
- **`/src/Renderer/`** - Response rendering (JSON, XML, Twig, Raw)
- **`/src/Infrastructure/`** - Framework wiring, diagnostics, server checks
- **`/src/Composer/`** - Composer plugin (ships the agent skill, install hooks)
- **`/src/Handler/`**, **`/src/Support/`**, **`/src/Traits/`**, **`/src/Transformer/`** - Cross-cutting helpers
- **`/src/Utils/`** - Utility classes for file handling, image processing, QR codes
- **`/config/`** - Hierarchical PHP configuration and route definitions
- **`/tcms-data/`** - JSON-based flat-file storage for collections
- **`/tcms-data/extensions/`** - Third-party extensions installed as `{vendor}/{name}/`
- **`/resources/schemas/`** - JSON schemas for data validation
- **`/resources/templates/`** - Twig templates for admin interface
- **`/resources/docs/`** - Documentation files (source of truth for docs.totalcms.co)
- **`/resources/fonts/`** - Centralized font storage (default: RobotoRegular.ttf)
- **`/tests/test-data/`** - Test datasets for integration testing
- **`/tests/fixtures/extensions/`** - Test extension fixtures (must be committed to git)

### Design Patterns
- **Domain-Driven Design**: Clear separation between Actions, Domain services, and Data layers
- **Repository Pattern**: Data access abstraction with JSON storage
- **Dependency Injection**: PHP-DI container with interface-based design
- **Middleware Pipeline**: Authentication, CORS, license validation, request transformation
- **HTMX Integration**: Server-rendered partial HTML responses for interactive UI without heavy JS

## Key Features

- **Collection System**: 33 reserved schemas (`SchemaData::RESERVED_SCHEMAS` — blog, image, gallery, builder-page, automations, mcp-*, etc.) plus user-defined custom schemas, all stored as JSON files
- **Collection Reports**: Reporting API and admin utility for collection data
- **Data Views**: Saved, filtered, dependency-resolved views across collections, rebuilt on a schedule. Pro+ edition feature with its own access middleware; exposed to MCP via `list_views` / `get_view` / `query_view` / `describe_view`
- **Search**: Pluggable `SearchProvider` abstraction (built-in `text` by default, Algolia optional), with index jobs and event listeners; `tcms search:reindex` rebuilds
- **XML-RPC Publishing**: WordPress-compatible endpoint so MarsEdit/Byword/Ulysses can publish into T3. **Off by default** — gated behind `$settings['xmlrpc']`
- **Data Visualizers**: Admin relationship diagrams — collection ERD and object flowchart, both Mermaid, sharing one `RelationshipAnalyzer`
- **Site Builder**: Dynamic page router serving `builder-pages` collection objects at configurable URL patterns, with starter scaffolding, template designer, and optional Vite frontend pipeline
- **Setup Wizard**: First-run web wizard (welcome → environment → data-path → account → license → server-config → complete) for operator onboarding, with auto-login on account creation
- **Public Registration**: `/admin/register/{collection}` endpoint with opt-in allow-list for self-signup forms; auto-logs the new user in via `SessionLogin`
- **Load More System**: Frontend pagination with `loadMoreButton` for progressive content loading
- **Template Designer**: `{% templatedesigner %}` Twig tag for inline template definition synced to local + production servers (complements Load More)
- **Twig Playground**: Admin tool for testing and prototyping Twig templates with autosave
- **RSS & Sitemap Builders**: User-facing utilities for generating RSS feeds and sitemaps from collections
- **JumpStart System**: Data import/export with streaming support for large datasets; also powers starter-kit content seeding
- **Import Systems**: Migration from other platforms (Total CMS 1, Alloy CMS, Wordpress, CSV, JSON, RSS, URL) via job queue
- **ImageWorks System**: Image processing with text/image watermarking, custom font support, EXIF metadata
- **Twig Integration**: Custom filters/functions, `{% cmsgrid %}` tag, markdown processing, barcode extension
- **Admin Interface**: Form builder with 20+ field types, JavaScript components
- **Passkey Authentication**: WebAuthn passkey support for passwordless admin login
- **Cache System**: Multi-backend caching with APCu-first priority (APCu -> Redis -> Memcached -> Filesystem)
- **CLI Tool (`tcms`)**: Symfony Console CLI for collections, schemas, objects, JumpStart, sync, updates, builder scaffolding, search reindexing, and extension management
- **Extension System**: Two-phase lifecycle (register → boot) for third-party extensions with capability-based permissions
- **Event System**: Synchronous event dispatcher with 20 core events (object/collection/schema/template/user CRUD, import.*, bulk.deleted, extension lifecycle, devmode, cache.cleared)
- **Automations**: Schedule, webhook, and event-triggered automations with a job queue, run history, and guard rails; handlers are externalized code fields
- **Built-in MCP Server**: OAuth 2.1, schema/collection/data-view tools, read-only Site Builder template tools, resources, prompts, and search providers so AI agents can query the site directly
- **Composer Distribution**: Public Packagist distribution via `composer create-project totalcms/totalcms`
- **Build System**: ESBuild with code splitting

## Important Notes

- **Storage**: Flat-file JSON storage (no traditional database)
- **Caching**: Multi-backend Twig caching with APCu-first priority (APCu, Redis, Memcached, filesystem, OPcache)
- **Modern PHP**: Strict typing, PSR standards, PHP 8.2+ features with PHP 8.4 compatibility
- **Distribution**: Public Packagist via `totalcms/cms` (the library). Installed with `composer create-project totalcms/totalcms` (the project skeleton)
- **Extensions**: Third-party extensions in `tcms-data/extensions/{vendor}/{name}/` with auto-detected capability permissions
- **Enhanced Libraries**: Custom couleur fork with OKLCH improvements ([joeworkman-forks/couleur](https://github.com/joeworkman-forks/couleur))
- **Memory Management**: Streaming patterns for large datasets (see `JumpStartData::streamJsonToFile()` for examples)
- **Emergency Cache**: `/api/emergency/cache/clear` and `/api/emergency/cache/clear-license` endpoints for customer self-service cache clearing (note the `/api` prefix — both are registered inside the `/api` group)
- **Logging**: Zip installs log to `tcms-data/.system/logs` (survives updates); Composer installs log to `projectRoot/logs`. Nine-file LogFile/LogChannel taxonomy.
- **Releases**: `bin/prepare-release.sh` builds the dist zip, registers the version with the license API, and uploads to S3 (`totalcms-archive/releases/`). Licensed downloads via `license.totalcms.co/version/download/{version|latest}`; public latest-zip via `license.totalcms.co/download/latest`.

## Security Architecture

- **Session Management**: Use `Odan\Session\PhpSession` instead of direct `$_SESSION` access
- **CSRF Protection**: `CSRFTokenManager` + `CSRFProtectionMiddleware` with token validation from POST/headers/query
- **HTML Sanitization**: `HTMLSanitizer` in `src/Utils/` handles XSS prevention, cast `preg_replace()` to `(string)` for PHPStan
- **SVG Sanitization**: `SvgData` automatically sanitizes SVG content using `enshrined/svg-sanitize`
- **CSP Middleware**: Content Security Policy headers
- **CORS**: Limited to specific routes
- **File Upload Validation**: Security validation on all file uploads
- **File Path Protection**: Prevention of path traversal attacks

## Code Style & Conventions

### Naming Conventions
- Use tabs for indentation (not spaces)
- Private/protected class properties and methods use camelCase
- Constructor property promotion with `private`/`protected` visibility
- Array type hints: `@param array<string,mixed> $data`
- Method return types always specified

### Handler Patterns
- Handlers contain minimal HTTP logic, delegate to Services
- Services contain business logic and orchestrate Repositories
- Repositories handle database/storage operations
- Proper HTTP status codes (400 for validation, 404 for not found, 500 for server errors)

### Service Patterns
- Constructor dependency injection for repositories and other services
- Business logic validation in services (not handlers)
- Comprehensive error handling with meaningful exception messages
- Return arrays or data objects, not HTTP responses

### PHPStan Level 8 Compliance
- **Type Safety**: All methods must have explicit return types
- **Null Handling**: Use proper null checks and casting, especially for `preg_replace()` which can return null
- **Array Types**: Use specific array type hints like `@param array<string,mixed> $data`
- **Property Annotations**: Use `@phpstan-ignore-next-line` sparingly for edge cases
- **Testing**: Always run `composer run stan` after making changes to maintain Level 8 compliance

### Development Session Guidelines
- **Code Style**: Only run `composer run cs:fix` when explicitly requested - avoid during development as it makes tracking changes difficult
- **Quality Checks**: Use `composer run stan` for type checking, avoid mass formatting changes
- **Code Reports**: Only run `bin/code-report.sh` when creating new builds, not during development sessions
- **Change Tracking**: Keep git diffs clean by focusing on specific files being worked on

### Testing Best Practices
- **Run tests via Composer**: `composer test -- --filter=X` (sets `apc.enable_cli`, avoids MCP rate-limit flakiness) — never invoke `vendor/bin/pest` directly
- **API Endpoint Testing**: Use `postJson()` instead of `post()` for JSON endpoints
- **Flexible Status Codes**: Use `toBeIn([200, 400, 404, 405])` instead of exact matches for better test framework compatibility
- **Framework Compatibility**: Follow existing working test patterns (e.g., `AuthTest.php`) for reliable results
- **Test Data**: Maintain comprehensive test datasets in `/tests/test-data/` for integration testing
- **Error Handling**: Test both success and failure scenarios with graceful error handling
- **Stale Twig cache**: Twig render tests can serve stale compiled templates after a `.twig` edit (test env has `auto_reload` off) — `rm -rf cache/*` before re-running locally
- **Bundle integrity**: `resources/bundle` hashes `config/`, `resources/templates/` **and select `src/` files** — not just config. After editing anything it covers, rebuild with `composer run bundle` and commit the manifest alongside the change. Never hand-edit it. A stale manifest makes every write fail at runtime with 400 "installation has been corrupted"
  - **Tests do not catch this.** `tests/bootstrap.php` sets `APP_ENV=test`, which switches `BundleMiddleware`'s check off so the suite doesn't have to regenerate the manifest after every edit, and no test covers the manifest. `composer test` is plain pest with no bundle step. The gate is `composer run bundle:check` (~0.3s), which CI runs — but only after a push, so an install tracking `dev-develop` can pull the broken commit first
  - Install the pre-commit hook with `bin/install-hooks.sh` to run that check automatically. Note the manifest hashes the **working tree, not the index**: rebuild with unstaged edits to a covered file present and it records content that isn't in the commit

### CSS Styling Guidelines
- **Use Design System Variables**: Always use CSS variables from `/css/variables.scss` instead of hardcoding colors or values
- **Variable Format**: Use `oklch(var(--totalform-*))` for colors to ensure consistency with the design system
- **Common Variables**:
  - Border color: `oklch(var(--totalform-border-color))`
  - Background colors: `oklch(var(--totalform-nearwhite))`, `oklch(var(--totalform-icon-bg))`
  - Text colors: `oklch(var(--totalform-darkgray))`, `oklch(var(--totalform-text-color))`
  - Accent colors: `oklch(var(--totalform-accent))`, `oklch(var(--totalform-success))`
  - Border radius: `var(--totalform-radius)`
- **Avoid**: Custom colors, hardcoded values, non-existent variables

### Memory Management Best Practices
When working with large datasets (JumpStart exports, imports, bulk operations):
- **Streaming Pattern**: Process data incrementally instead of loading everything into memory
- **Immediate Cleanup**: Use `unset()` to free memory after processing each item in loops
- **Real-World Example**: See `JumpStartData::streamJsonToFile()` for complete streaming implementation
- **Key Principle**: Default to streaming patterns for any dataset that could potentially grow large

## Key System Notes

These are non-obvious details that are important when working in these areas:

### Twig Template System
- **Global Variable**: Use `cms` for accessing configuration, collections, and services
- **Configuration**: `cms.config('key')` not `config` (which doesn't exist)
- **Common Usage**: `cms.env`, `cms.config('debug')`, `cms.gallery()`, `cms.image()`
- **Grid System**: `{% cmsgrid %}` tag for content grids with helper methods in `cms.grid.*`

### ImageWorks System
- **Font Support**: TTF/OTF fonts from depot storage (default: RobotoRegular.ttf)
- **Configuration**: `watermarkFontsDepot` setting (default: 'watermark-fonts')
- **Color System**: Enhanced OKLCH color manipulation via custom couleur fork

### Template Designer
- **Architecture**: Custom Twig Loader preprocessor extracts raw block content before Twig compilation
- **API**: `PUT/HEAD /designer/templates/{path}` with `DesignerAccessMiddleware` (public, token-gated)
- **Schema**: `designerEnabled` (toggle) + `designerToken` (UUID, readonly)
- **Metadata**: Companion `.designer.json` files alongside `.twig` files

### Site Builder
- **Concept**: Dynamic page system where `builder-pages` collection objects are routed at request time by `PageRouterMiddleware`. No build/generate step — add a page in the admin, it's live.
- **Page records**: Objects in the `builder-pages` collection (schema: `builder-page`). Fields: `id`, `title`, `route` (template URL with `{id}` style placeholders), `template`, `draft`, `nav`, `data` (free-form JSON exposed as `page.data.*`), `status` (HTTP), `redirectTo`, `sitemap`, `middleware`, `accessGroups`
- **Templates**: Live at `tcms-data/builder/{layouts,pages,partials,macros}/*.twig`. `BuilderTwigAdapter` provides `cms.builder.nav()`, `cms.builder.url(pageId, params)`, `cms.builder.css/js/asset()` with mtime cache-busting
- **Page router**: `src/Middleware/PageRouterMiddleware.php` matches request paths against page routes, dispatches templated routes through `ObjectUrlBuilder`. Templated URLs (containing `{...}` placeholders) are implicitly pretty — the `prettyUrl` flag only applies to non-templated URL prefixes.
- **Starters**: `tcms builder:init <starter>` scaffolds from `resources/builder/starters/{name}/` — copies templates, ensures the `builder-pages` collection, runs the starter's `jumpstart.json` to seed pages + demo content. Bundled: `minimal`, `blog`, `business`, `portfolio`
- **JumpStart-driven**: Starter pages live in `jumpstart.json` as `builder-pages` objects (NOT in `manifest.json` — manifest is metadata only). Reserved-collection entries support overrides (e.g. `{"id": "blog", "url": "/blog/{id}"}`) to set the URL/sortBy alongside the schema-bound default.
- **Frontend pipeline**: Optional Vite scaffold via `tcms builder:frontend` (or `--frontend` flag on `builder:init`) — drops a customer-editable `frontend/` directory with `vite.config.js`, compiles to `public/assets/`
- **Key files**: `PageRouterMiddleware`, `BuilderTwigAdapter`, `BuilderInstaller`, `StarterService`, `BuilderOrderService` (sidebar ordering via `.order.json`)

### Setup Wizard
- **Flow**: First-run web wizard — `welcome` → `environment` → `data-path` → `account` → `license` → `server-config` → `complete`. State persisted in `<datadir>/.system/setup-state.json` (HMAC-signed elsewhere; here it's just step tracking)
- **Middleware**: `SetupCheckMiddleware` runs BEFORE Slim's RoutingMiddleware so it can intercept unrouted requests (like `/`). When setup is incomplete it redirects page navigation to the current wizard step; asset/API requests fall through to normal 404 handling.
- **Account step**: `AccountSetupSubmitAction` creates the first admin user via `FirstLoginChecker`, stashes the email in session (`setup_admin_email`) so it pre-fills the form on validation-failure redirects AND displays on the complete page. After successful save it auto-logs the operator in via `SessionLogin::establish()` so they don't have to retype credentials at the end of the wizard.
- **Server-config step**: Renders rewrite-rule snippets for Apache + Nginx. Detects whether `public/.htaccess` already ships (Composer install) and switches the Apache panel between "rules already in place" and "paste this in" messaging.
- **Subpath layout**: `bin/post-install.php` in the project skeleton supports a `subpath` layout option that moves `public/index.php` and `public/.htaccess` into `public/tcms/` and bumps the `TCMS_PROJECT_ROOT` dirname depth.
- **Key files**: `src/Domain/Setup/`, `src/Action/Setup/`, `SetupCheckMiddleware`, `DataPathInstaller`

### Auth: SessionLogin + Public Registration
- **`SessionLogin`** (`src/Domain/Auth/Service/SessionLogin.php`): Single source of truth for "log this user in." Writes the four session keys (`AUTH_USER`, `AUTH_COLLECTION`, `AUTH_PERSISTENT_LOGIN`, `LICENSE_CHECK_DUE`) in the same order across every entry point. Used by `AuthLoginSubmitAction`, `AccountSetupSubmitAction`, and `AuthRegisterSubmitAction`. Does NOT authenticate — caller verifies the user first.
- **Public registration endpoint**: `POST /admin/register/{collection}` (`AuthRegisterSubmitAction`). Creates a user via `ObjectSaver`, calls `LoginService::authenticate()` for verification, then `SessionLogin::establish()`. Returns JSON in the same shape as `ObjectSaveAction` so the form builder can chain deferred uploads + actions.
- **Allow-list**: `$config->auth['publicRegistration']` is an opt-in list of collection IDs. Empty by default — the default `auth` collection (operator-only) is never exposed. Endpoint throws `HttpForbiddenException` for collections not in the list.
- **Form builder integration**: `cms.form.builder('members', {register: true})` retargets the form at `/admin/register/{collection}`, forces `addOnly: true` (the endpoint has no PUT route), and rewrites `data-api` to drop the `/api` prefix
- **`auth.loginWith` config**: `'email'`, `'id'`, or `'both'`. `UserValidationService::validateUser($idOrEmail, $collection)` dispatches transparently; for `'both'` it picks based on `@` in the input. The login form's identifier field is always POSTed as `email` for backwards-compat — the variable name is misleading.
- **Security caveats the operator owns** (documented in `auth.publicRegistration` config block + `wizard.account` flow): registrants are auto-logged in, so any unprotected form on a site with gated content exposes that content to bots. Gate with CAPTCHA / rate limit / email verification when the access group new users land in reaches sensitive content.

### Extension System
- **Lifecycle**: Two-phase — `register()` during container build, `boot()` after routes are loaded
- **API Surface**: `ExtensionContext` provides curated methods; extensions never touch the raw container directly
- **Extension Points**: Twig functions/filters, CLI commands, routes (API/public/admin), admin nav items, dashboard widgets, custom field types, event listeners, admin assets, container definitions, schemas
- **Capability Detection**: After `register()`, the system detects what the extension actually registered (not self-declared). Capabilities become toggleable permissions in the admin UI.
- **Permissions**: Stored in `tcms-data/.system/extensions.json` per-extension. Admins can disable individual capabilities without uninstalling. All `getAll*()` accessors filter by permission state.
- **Settings**: Per-extension custom settings in `tcms-data/.system/extension-settings/{vendor}/{name}.json`. Settings schemas use the same `type` + `field` format as collection/settings schemas.
- **Routes**: Extensions register routes via `RouteCollector` (not Slim directly). Three static route handlers dispatch at runtime: `ExtensionRouteAction` (API), `ExtensionAdminRouteAction` (admin), `ExtensionAssetAction` (static assets).
- **Admin UI**: Extension management page with enable/disable, auto-generated permission toggles, and custom settings forms via `TotalFormFactory::extensionSettings()`.
- **Twig Collision Protection**: `TwigExtensionRegistrar` blocks extensions from overriding core Twig functions/filters and warns on extension-to-extension collisions.
- **Fault Isolation**: Every `register()` and `boot()` call is wrapped in try/catch. Failures are logged, recorded in state, and the extension is skipped.
- **Key Files**: `ExtensionManager` (orchestrator), `ExtensionContext` (public API), `ExtensionDiscovery` (filesystem scanner), `ExtensionState` (runtime state with permissions)

### CLI System (`tcms`)
- **Framework**: Symfony Console via `CliApplication`
- **Entry Point**: `resources/bin/tcms` (shipped; exposed as `vendor/bin/tcms` via Composer `bin`). In this repo run it as `php resources/bin/tcms` — there is no `bin/tcms` symlink.
- **Commands**: `collection:list|get|export|import|query`, `object:list|get|create|patch|export|delete`, `schema:list|get|export|import|lint`, `jumpstart:export|import`, `builder:init|frontend|routes|history`, `extension:list|enable|disable|remove`, `update:check|apply|rollback`, `automations:process`, `jobs:process`, `mcp:status`, `mcp:test`, `oauth:setup`, `oauth:gc`, `repair:files`, `repair:index`, `rss:import`, `search:reindex`, `deck:import`, `cache:clear`, `skill:install`, `deploy`, `info`, `pull`, `push`
- **Extension Commands**: Loaded after core commands with collision protection (extensions cannot shadow built-in command names)
- **Output Formats**: Human-readable tables by default, `--json` flag for machine-readable output

### Event System
- **Dispatcher**: `src/Domain/Event/Service/EventDispatcher.php` — synchronous, priority-ordered
- **Event names**: Always use the `CoreEvent` consts (`src/Domain/Event/Data/CoreEvent.php`) at `dispatch()`/`listen()` call sites, never raw strings
- **Core Events** (20): `object.created`, `object.updated`, `object.deleted`, `collection.created`, `collection.updated`, `collection.deleted`, `schema.saved`, `schema.deleted`, `template.saved`, `user.login`, `user.logout`, `import.created`, `import.updated`, `import.completed`, `bulk.deleted`, `extension.enabled`, `extension.disabled`, `devmode.enabled`, `devmode.disabled`, `cache.cleared`
- **Integration**: EventDispatcher is injected into ObjectSaver, ObjectUpdater, ObjectRemover, CollectionSaver, CollectionRemover, LoginService, LogoutService, SchemaSaver, SchemaRemover, TemplateSaver, ExtensionManager, ObjectImporter, JumpStartImporter, DeckJsonImporter, DeckCsvImporter
- **Extension Listeners**: Registered via `$context->addEventListener()`, wired into the dispatcher during boot. Listeners execute in try/catch so a broken listener cannot affect core operations.
- **Import-Time Behavior**: While a collection is mid-import (`EventDispatcher::suspendForImport($collection)`), `object.created` and `object.updated` events are **suppressed** for that collection — importers fire `import.created` / `import.updated` per object instead, with the same `ObjectEventPayload` shape. Listeners that want to react to import-time writes specifically subscribe to the `import.*` events. `import.completed` auto-resumes the suspension (safety net for forgetful importers). `ObjectImporter` self-suspends when called outside an explicit lifecycle (e.g. `JobRunner` processing a single queued job).

### Automations
- **Triggers**: `schedule` (cron-style via `ScheduleTicker`), `webhook` (`AutomationWebhookAction`), and `event` (`AutomationEventSubscriber` bridging the core event dispatcher)
- **Storage**: Automations are objects in the reserved `automations` collection (`automations` + `automation-trigger` schemas); handlers are externalized code fields (`code.json` `$ref` → `CodeData`)
- **Execution**: `AutomationRunner` executes handlers with an `AutomationContext` (schedule runs have `request`/`event` null; event runs carry the event payload). `AutomationQueue` + `tcms automations:process` handle queued runs; `AutomationGuard` provides guard rails; run history via `RunRecord`/`AutomationRunReader`
- **Key files**: `src/Domain/Automation/`, `src/Action/Automation/` (webhook, run-now, re-enable), `src/Middleware/Automation/`

### Search
- **Providers**: `$settings['search']['activeProvider']` is `'text'` (built-in) or a registered provider id such as `'algolia'`. Providers register through the `SearchProvider` interface, so extensions can add their own.
- **`indexOnSave` gotcha**: when true, `object.created` / `object.updated` events push straight to the active provider's `index()`. **Disable it during bulk imports** to avoid hammering an external embedding/index API, then re-enable and run `tcms search:reindex`.
- **Structure**: `src/Domain/Search/` splits Data / Job / Listener / Service

### Data Views
- **Concept**: A saved, filtered, dependency-resolved query across one or more collections, materialized on a schedule rather than computed per request. Stored via the reserved `dataviews` schema.
- **Double gate**: `DataViewsEditionMiddleware` (Pro+ edition, `EditionFeature::DATA_VIEWS`) **and** `DataViewsAccessMiddleware` (per-user access groups). Both must pass — an edition check alone is not authorization.
- **Services**: `src/Domain/DataView/Service/` — `DataViewBuilder` (materialize), `DataViewFetcher`, `DataViewQueryService`, `DataViewFilter`, `DataViewDependencyResolver` (which collections a view depends on), `DataViewUpdateScheduler` (rebuild cadence), `DataViewLister`, `DataViewRemover`
- **Surfaces**: admin page (`AdminDataViewsAction`), REST actions (`src/Action/DataView/` — query, fetch, rebuild, test), and MCP tools (`list_views`, `get_view`, `query_view`, `describe_view`)
- **Access groups**: `dataviews` is a grantable resource in `AccessGroupData` — check `AccessControlService` when changing view visibility

### XML-RPC Publishing
- **Purpose**: WordPress-compatible XML-RPC so desktop editors (MarsEdit, Byword, Ulysses) publish into T3
- **Off by default**: `$settings['xmlrpc']` is `['enable' => false, 'ratePerIp' => 60]` (`config/defaults.php`); route in `config/routes/public/xmlrpc.php`
- **Structure**: `src/Domain/XmlRpc/` splits Transport (parser/writer/fault), Handler (blog, post read/write, taxonomy, system, unsupported), and Service (`MethodRouter`, `PostMapper`, `BlogRegistry`, `XmlRpcAuth`)
- **Auth**: API keys as the credential, not session cookies — see `XmlRpcAuth`
- **`editPost` must patch, not replace** — clients send partial post structs; a full replace silently drops fields the editor did not send

### Configuration System
- **Deep Merge**: Override specific nested settings without replacing entire arrays
- **Usage**: Return array from tcms.php for deep merging
- **Type Safety**: All array properties protected with `is_array()` validation

### License System
- **Validation Flow**: Middleware -> Service -> API call -> JWT validation -> Cache
- **Data Structure**: 8 essential fields (valid, trial, domain, edition, message, validationToken, updatesValid, trialDaysRemaining)
- **Cache Integration**: Multi-backend with 24-hour TTL
- **Version Authorization**: License API validates the running T3 version is authorized for the license. Unauthorized versions show a dashboard warning.

### Edition Gating
- **Pattern**: Paid features are gated per-edition at the route level, not inline in services. `EditionFeature` (`src/Domain/License/Data/EditionFeature.php`) enumerates the gated features; each gets a thin middleware extending `BaseEditionMiddleware` that returns its `EditionFeature`, attached to the relevant route group.
- **Current features** (10): `custom_schemas`, `image_watermarks`, `external_rest_api`, `qr_codes`, `barcodes`, `data_views`, `rss_import`, `bulk_mailer`, `passkeys`, `access_groups`
- **Middlewares** live in `src/Middleware/License/` — 14 of them, since some features gate more than one route group (collections, schemas, templates, API keys, OAuth, automations, mailer)
- **When adding a feature**: decide early whether it is edition-gated. Adding the gate later means retrofitting routes, and the middleware is the only enforcement point — services do not check editions themselves.

### Documentation (`resources/docs/`)
- **Source of truth**: `resources/docs/*.md` is mirrored to docs.totalcms.co. Template changes to `resources/templates/admin/docs.twig` only affect the in-admin viewer — the public site has its own template that needs parallel changes.
- **Sidebar menu** lives in `resources/docs/menu.php` (shared by `AdminDocsAction` and `bin/build-docs-index.php`). 15 top-level groups: Get Started, Collections, Schemas, Fields, Site Builder, Twig, Forms, Automations, Admin, Notifications, Auth, APIs, MCP, Extensions & CLI, Operations. Adding a new doc page = add a `{title, path}` entry to the appropriate group. Fields, Twig, and Extensions & CLI use nested subgroups (the last via mixed `sub` + `groups`); everything else is flat.
- **Folder convention**: each doc lives in `resources/docs/<kebab-cased-group-name>/<page>.md` matching its menu group (e.g. `get-started/`, `site-builder/`, `apis/`, `operations/`). Subgroups (Field Types, Field Options, Twig Basics, etc.) exist only in the menu — the files themselves are flat within the group folder. URL = path = file path under `resources/docs/`.
- **Images & screenshots**: co-locate with the section that uses them, in `resources/docs/<section>/images/<name>.png`. Reference in markdown as `docs/<section>/images/<name>.png` (the `docs/` prefix is required because of the admin's `<base href>`). `AdminDocsAction` serves png/jpg/gif/svg/webp at the same route as markdown pages — see the image-mime branch in that file. Use kebab-case filenames.
- **Navigation primitives**: breadcrumbs, prev/next, and the related-pages footer are all derived from the menu — no extra config needed. Breadcrumb group label = whichever menu group the page lives in.
- **No synthetic landing pages**: each group's first sub-entry is its natural overview (e.g. `builder/overview` for Site Builder, `extensions/overview` for Extensions). Avoid adding "Overview" entries that point to fabricated section landings — keep the natural intro page first instead.
- **Frontmatter conventions** (all optional):
  - `title:` — H1 fallback, displayed in breadcrumbs and search results
  - `description:` — used by search
  - `related:` — list of paths (e.g. `twig/data`) to render a "Related pages" block at the bottom of the page. Resolved against the menu for titles.
  - `audience:` — `beginner | intermediate | advanced`. Stored but not displayed yet.
  - `updated:` — date string, displayed in the page footer when present.
- **Doc indexes**: `bin/build-docs-index.php` regenerates BOTH `resources/docs/search-index.json` (page search) and `resources/docs/reference-index.json` (structured reference: Twig functions/filters via reflection, field types, API endpoints, schema config, CLI commands, extension/builder API — consumed by the docs MCP extension's `docs_lookup`). **Both are checked into git** because they ship with the Composer package. Rebuild and commit after adding, renaming, or substantially editing doc pages; `tests/Unit/Docs/ReferenceIndexTest.php` fails on staleness or under-populated kinds.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
