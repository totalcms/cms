# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Total CMS is a modern PHP-based Content Management System using flat-file JSON storage. Built with Slim 4 framework, it provides a RESTful API with Twig templating and a comprehensive admin interface. The product is in production with 200+ sites. Current development is on 3.3 which adds the CLI, extension system, event system, and Composer distribution.

### Related Projects
- **Total CMS License API** ([totalcms/license.totalcms.co](https://github.com/totalcms/license.totalcms.co)): License validation and trial management with similar Slim 4 architecture
- **Total CMS 3 Stacks**: Stacks plugin for the Stacks platform
- **Documentation Site** ([totalcms/docs.totalcms.co](https://github.com/totalcms/docs.totalcms.co)): Public docs at docs.totalcms.co. Source of truth is `/resources/docs/` in this repo; synced to the docs site via the build script
- **Extension Starter** ([totalcms/extension-starter](https://github.com/totalcms/extension-starter)): Template repo for building T3 extensions, demonstrates every extension point
- **MCP Docs Server** ([totalcms/mcp.totalcms.co](https://github.com/totalcms/mcp.totalcms.co)): MCP server that serves T3 documentation to AI agents
- **Project Repo** ([totalcms/totalcms-project](https://github.com/totalcms/totalcms-project)): Composer project template for installing T3 via `composer create-project`

## Technology Stack

- **Backend**: PHP 8.2+, Slim 4, Twig 3, PHP-DI 7, PSR-7/PSR-15
- **Frontend**: ESBuild, Sass/SCSS, TypeScript/ES6+, HTMX 4.0, Node.js/Yarn
- **Rich Text**: Tiptap editor (replaced Froala)
- **Testing**: Pest (PHP testing), PHPStan Level 8, PHP-CS-Fixer, PHPMD

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

# Run all quality checks
composer run test:all
```

## Architecture Overview

### Directory Structure
- **`/src/Action/`** - HTTP action handlers organized by domain (Admin, Auth, Collection, Extension, Upload, etc.)
- **`/src/Domain/`** - Business logic layer with services, repositories, and data objects
  - **`/src/Domain/Extension/`** - Extension system: discovery, lifecycle, permissions, settings, route collection
  - **`/src/Domain/Event/`** - Core event dispatcher (used by extensions and internal services)
  - **`/src/Domain/JumpStart/`** - JumpStart data import/export system
  - **`/src/Domain/Import/`** - CMS import systems (Alloy, Total CMS 1, Wordpress, CSV, JSON, RSS, URL)
  - **`/src/Domain/Factory/`** - Factory system for generating test data using Faker
  - **`/src/Domain/ImageWorks/`** - Image processing with watermarking, font management, and caching
  - **`/src/Domain/Twig/`** - Twig templating system with adapters, extensions, and custom functions
- **`/src/CLI/`** - Symfony Console CLI application and commands
- **`/src/Middleware/`** - HTTP middleware for auth, CORS, licensing, validation
- **`/src/Renderer/`** - Response rendering (JSON, XML, Twig, Raw)
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

- **Collection System**: 13 built-in collection types (blog, image, gallery, etc.) stored as JSON files
- **Collection Reports**: Reporting API and admin utility for collection data
- **Load More System**: Frontend pagination with `loadMoreButton` for progressive content loading
- **Template Designer**: `{% templatedesigner %}` Twig tag for inline template definition synced to local + production servers (complements Load More)
- **Twig Playground**: Admin tool for testing and prototyping Twig templates with autosave
- **RSS & Sitemap Builders**: User-facing utilities for generating RSS feeds and sitemaps from collections
- **JumpStart System**: Data import/export with streaming support for large datasets
- **Import Systems**: Migration from other platforms (Total CMS 1, Alloy CMS, Wordpress, CSV, JSON, RSS, URL) via job queue
- **ImageWorks System**: Image processing with text/image watermarking, custom font support, EXIF metadata
- **Twig Integration**: Custom filters/functions, `{% cmsgrid %}` tag, markdown processing, barcode extension
- **Admin Interface**: Form builder with 20+ field types, JavaScript components
- **Passkey Authentication**: WebAuthn passkey support for passwordless admin login
- **Cache System**: Multi-backend caching with APCu-first priority (APCu -> Redis -> Memcached -> Filesystem)
- **CLI Tool (`tcms`)**: Symfony Console CLI for collections, schemas, objects, JumpStart, sync, updates, and extension management
- **Extension System**: Two-phase lifecycle (register → boot) for third-party extensions with capability-based permissions
- **Event System**: Synchronous event dispatcher with 11 core events (object/collection/schema/user CRUD, extension lifecycle)
- **Composer Distribution**: Public Packagist distribution via `composer create-project totalcms/project`
- **Build System**: ESBuild with code splitting

## Important Notes

- **Storage**: Flat-file JSON storage (no traditional database)
- **Caching**: Multi-backend Twig caching with APCu-first priority (APCu, Redis, Memcached, filesystem, OPcache)
- **Modern PHP**: Strict typing, PSR standards, PHP 8.2+ features with PHP 8.4 compatibility
- **Distribution**: Public Packagist via `totalcms/cms`. Installed with `composer create-project totalcms/project`
- **Extensions**: Third-party extensions in `tcms-data/extensions/{vendor}/{name}/` with auto-detected capability permissions
- **Enhanced Libraries**: Custom couleur fork with OKLCH improvements ([joeworkman-forks/couleur](https://github.com/joeworkman-forks/couleur))
- **Memory Management**: Streaming patterns for large datasets (see `JumpStartData::streamJsonToFile()` for examples)
- **Emergency Cache**: `/emergency/cache/clear` endpoint for customer self-service cache clearing

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
- **API Endpoint Testing**: Use `postJson()` instead of `post()` for JSON endpoints
- **Flexible Status Codes**: Use `toBeIn([200, 400, 404, 405])` instead of exact matches for better test framework compatibility
- **Framework Compatibility**: Follow existing working test patterns (e.g., `AuthTest.php`) for reliable results
- **Test Data**: Maintain comprehensive test datasets in `/tests/test-data/` for integration testing
- **Error Handling**: Test both success and failure scenarios with graceful error handling

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
- **Entry Point**: `resources/bin/tcms` (shipped), `bin/tcms` (dev symlink)
- **Commands**: `collection:list`, `collection:get`, `collection:export`, `collection:import`, `collection:query`, `object:list`, `object:get`, `object:export`, `schema:list`, `schema:get`, `schema:export`, `schema:import`, `jumpstart:export`, `jumpstart:import`, `extension:list`, `extension:enable`, `extension:disable`, `extension:remove`, `update:check`, `update:apply`, `update:rollback`, `cache:clear`, `info`, `pull`, `push`, `deck:import`, `jobs:process`
- **Extension Commands**: Loaded after core commands with collision protection (extensions cannot shadow built-in command names)
- **Output Formats**: Human-readable tables by default, `--json` flag for machine-readable output

### Event System
- **Dispatcher**: `src/Domain/Event/EventDispatcher.php` — synchronous, priority-ordered
- **Core Events**: `object.created`, `object.updated`, `object.deleted`, `collection.created`, `collection.deleted`, `schema.saved`, `schema.deleted`, `user.login`, `user.logout`, `extension.enabled`, `extension.disabled`, `devmode.enabled`, `devmode.disabled`, `cache.cleared`
- **Integration**: EventDispatcher is injected into ObjectSaver, ObjectUpdater, ObjectRemover, CollectionSaver, CollectionRemover, LoginService, LogoutService, SchemaSaver, SchemaRemover, ExtensionManager
- **Extension Listeners**: Registered via `$context->addEventListener()`, wired into the dispatcher during boot. Listeners execute in try/catch so a broken listener cannot affect core operations.

### Configuration System
- **Deep Merge**: Override specific nested settings without replacing entire arrays
- **Usage**: Return array from tcms.php for deep merging
- **Type Safety**: All array properties protected with `is_array()` validation

### License System
- **Validation Flow**: Middleware -> Service -> API call -> JWT validation -> Cache
- **Data Structure**: 8 essential fields (valid, trial, domain, edition, message, validationToken, updatesValid, trialDaysRemaining)
- **Cache Integration**: Multi-backend with 24-hour TTL
- **Version Authorization**: License API validates the running T3 version is authorized for the license. Unauthorized versions show a dashboard warning.
