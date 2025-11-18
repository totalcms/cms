# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Total CMS is a modern PHP-based Content Management System using flat-file JSON storage. Built with Slim 4 framework, it provides a RESTful API with Twig templating and a comprehensive admin interface.

### Related Projects
- **Total CMS License API** (`/Users/joeworkman/Websites/license.totalcms.co`): License validation and trial management with similar Slim 4 architecture

### Development Priorities (Pre-3.1 Launch)

**Current Phase**: Beta development with focus on stability and documentation

**Primary Focus Areas**:
1. **User Documentation Quality**: The biggest gap before public launch is comprehensive, well-organized documentation for end users
2. **Feature Completion**: Core feature set is stable and production-ready
3. **Code Quality**: Maintaining PHPStan Level 8, comprehensive test coverage, and clean architecture
4. **Performance**: Memory management, caching, and optimization for large datasets

**Post-3.1 Goals**:
- Dark mode support for admin interface
- Additional import adapters for other CMS platforms
- Performance optimizations based on production usage
- Community-contributed templates and extensions

## Technology Stack

- **Backend**: PHP 8.2+, Slim 4, Twig 3, PHP-DI 7, PSR-7/PSR-15
- **Frontend**: ESBuild, Sass/SCSS, TypeScript/ES6+, Node.js/Yarn
- **Testing**: Pest (PHP testing), PHPStan Level 8, PHP-CS-Fixer, PHPMD

## Common Development Commands

### Build and Development

You do not need to work about Frontend asset building (primary development command)
There is a watch script in dev that will autobuild all front end assets

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
- **`/src/Action/`** - HTTP action handlers organized by domain (Admin, Auth, Collection, etc.)
- **`/src/Domain/`** - Business logic layer with services, repositories, and data objects
  - **`/src/Domain/JumpStart/`** - JumpStart data import/export system
  - **`/src/Domain/Import/`** - CMS import systems (Alloy, Total CMS 1, etc.)
  - **`/src/Domain/Factory/`** - Factory system for generating test data using Faker
  - **`/src/Domain/ImageWorks/`** - Complete image processing with watermarking, font management, and caching
  - **`/src/Domain/Twig/`** - Twig templating system with adapters, extensions, and custom functions
- **`/src/Middleware/`** - HTTP middleware for auth, CORS, licensing, validation
- **`/src/Renderer/`** - Response rendering (JSON, XML, Twig, Raw)
- **`/src/Utils/`** - Utility classes for file handling, image processing, QR codes
- **`/config/`** - Hierarchical PHP configuration and route definitions
- **`/tcms-data/`** - JSON-based flat-file storage for collections
- **`/resources/schemas/`** - JSON schemas for data validation
- **`/resources/templates/`** - Twig templates for admin interface
- **`/resources/docs/`** - Documentation files
- **`/resources/fonts/`** - Centralized font storage (default: RobotoRegular.ttf)
- **`/tests/test-data/`** - Test datasets for integration testing

### Design Patterns
- **Domain-Driven Design**: Clear separation between Actions, Domain services, and Data layers
- **Repository Pattern**: Data access abstraction with JSON storage
- **Dependency Injection**: PHP-DI container with interface-based design
- **Middleware Pipeline**: Authentication, CORS, license validation, request transformation

## Key Features

- **Collection System**: 13 built-in collection types (blog, image, gallery, etc.) stored as JSON files
- **JumpStart System**: Data import/export with streaming support for large datasets
- **Import Systems**: Migrating from other CMS platforms (Total CMS 1, Alloy CMS) via job queue
- **ImageWorks System**: Image processing with text/image watermarking, custom font support
- **Twig Integration**: Custom filters/functions, `{% cmsgrid %}` tag, markdown processing
- **Admin Interface**: Form builder with 20+ field types, JavaScript components
- **Cache System**: Multi-backend caching with APCu-first priority (APCu → Redis → Memcached → Filesystem)
- **Build System**: ESBuild with code splitting

## Important Notes

- **Storage**: Flat-file JSON storage (no traditional database)
- **Caching**: Multi-backend Twig caching with APCu-first priority (APCu, Redis, Memcached, filesystem, OPcache)
- **Modern PHP**: Strict typing, PSR standards, PHP 8.2+ features with PHP 8.4 compatibility
- **Enhanced Libraries**: Custom couleur fork with OKLCH improvements at `/Users/joeworkman/Developer/forks/couleur`
- **Memory Management**: Streaming patterns for large datasets (see `JumpStartData::streamJsonToFile()` for examples)
- **Emergency Cache**: `/emergency/cache/clear` endpoint for customer self-service cache clearing

## Security Architecture

- **Session Management**: Use `Odan\Session\PhpSession` instead of direct `$_SESSION` access
- **CSRF Protection**: `CSRFTokenManager` + `CSRFProtectionMiddleware` with token validation from POST/headers/query
- **HTML Sanitization**: `HTMLSanitizer` in `src/Utils/` handles XSS prevention, cast `preg_replace()` to `(string)` for PHPStan
- **SVG Sanitization**: `SvgData` automatically sanitizes SVG content using `enshrined/svg-sanitize`

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
- **Helper Methods**: Create reusable helpers that handle JSON encoding errors
- **Real-World Example**: See `JumpStartData::streamJsonToFile()` for complete streaming implementation
- **Key Principle**: Default to streaming patterns for any dataset that could potentially grow large

## Major System Overviews

### Twig Template System
- **Global Variable**: Use `cms` for accessing configuration, collections, and services
- **Configuration**: `cms.config('key')` not `config` (which doesn't exist)
- **Common Usage**: `cms.env`, `cms.config('debug')`, `cms.gallery()`, `cms.image()`
- **Custom Extensions**: TotalCMSTwigExtension with CMS-specific filters and functions
- **Grid System**: `{% cmsgrid %}` tag for content grids with helper methods in `cms.grid.*`

### ImageWorks System
- **Components**: TextWatermarkFactory, GlideFactory, ImageGenerator, ImageMetaReader
- **Font Support**: TTF/OTF fonts from depot storage (default: RobotoRegular.ttf)
- **Configuration**: `watermarkFontsDepot` setting (default: 'watermark-fonts')
- **EXIF Metadata**: Native PHP implementation for PHP 8.4 compatibility, auto-populates alt text and tags
- **Color System**: Enhanced OKLCH color manipulation via custom couleur fork

### JumpStart System
- **Purpose**: Data import/export system for quick project setup
- **Streaming**: Memory-efficient exports using `streamJsonToFile()` for large datasets
- **Export Order**: schemas → collections → templates → objects → factory
- **Factory Support**: Generate test data using Faker instead of real content
- **Admin Interface**: `/admin/utils/jumpstart` for export/import operations

### Cache System
- **Priority Order**: APCu → Redis → Memcached → Filesystem (OPcache automatic)
- **Configuration**: See `config/defaults.php` for cache backend settings
- **Management**: Admin UI with hit rates, memory usage, and per-backend clearing
- **Emergency Clearing**: `/emergency/cache/clear` endpoint for customer self-service

### Import Systems
- **Alloy CMS**: Import blogs, embeds, droplets via job queue (`src/Domain/Import/AlloyImporter.php`)
- **Total CMS 1**: Migration support via JumpStart system
- **Admin Interface**: `/admin/utils/project-setup` for import operations
- **Processing**: Background job queue processing for large imports

### SimpleForm System
- **Purpose**: Lightweight form builder for basic admin operations (cache clearing, settings forms)
- **Features**: CSRF protection, REST method support, AJAX configurable, button customization
- **Usage**: Use for single-action forms; use full TotalForm for complex multi-field forms
- **JavaScript**: Works with `totalform.js` for automatic AJAX handling

### Object Management
- **ObjectCloner**: Enhanced cloning with automatic `onCreate`/`onUpdate` date field handling
- **Schema Integration**: Uses `SchemaFetcher` to identify date fields with special settings

### Form Field System
- **Relational Options**: Multi-field labels with space-separated field names
- **Documentation**: Comprehensive field settings in `/resources/docs/field-settings.md`

### License System
- **Validation Flow**: Middleware → Service → API call → JWT validation → Cache
- **Data Structure**: 8 essential fields (valid, trial, domain, edition, message, validationToken, updatesValid, trialDaysRemaining)
- **Cache Integration**: Multi-backend with 24-hour TTL

### Configuration System
- **Deep Merge**: Override specific nested settings without replacing entire arrays
- **Usage**: Return array from tcms.php for deep merging
- **Type Safety**: All array properties protected with `is_array()` validation
