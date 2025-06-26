# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Total CMS is a modern PHP-based Content Management System using flat-file JSON storage. Built with Slim 4 framework, it provides a RESTful API with Twig templating and a comprehensive admin interface.

### Related Projects
- **Total CMS License API** (`/Users/joeworkman/Websites/license.totalcms.co`): License validation and trial management with similar Slim 4 architecture

## Technology Stack

- **Backend**: PHP 8.2+, Slim 4, Twig 3, PHP-DI 7, PSR-7/PSR-15
- **Frontend**: ESBuild, Sass/SCSS, TypeScript/ES6+, Node.js/Yarn
- **Testing**: Pest (PHP testing), PHPStan Level 8, PHP-CS-Fixer, PHPMD

## Common Development Commands

### Build and Development
```bash
# Full application build
composer run build
bin/build.sh

# Frontend asset building
composer run esbuild
bin/build-assets.sh
yarn build

# Development with file watching
bin/watch.sh
bin/devserver.sh

# Create distribution bundle
bin/make-bundle.php
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
composer run test:coverage

# Mess detection
composer run md

# Run all quality checks
composer run test:all
```

### Application Management
```bash
# Schema management
composer run schema:dump

# Code analysis and reporting
bin/code-report.sh
bin/codecount.sh
```

## Architecture Overview

### Directory Structure
- **`/src/Action/`** - HTTP action handlers organized by domain (Admin, Auth, Collection, etc.)
- **`/src/Domain/`** - Business logic layer with services, repositories, and data objects
- **`/src/Middleware/`** - HTTP middleware for auth, CORS, licensing, validation
- **`/src/Renderer/`** - Response rendering (JSON, XML, Twig, Raw)
- **`/src/Utils/`** - Utility classes for file handling, image processing, QR codes
- **`/config/`** - Hierarchical PHP configuration and route definitions
- **`/tcms-data/`** - JSON-based flat-file storage for collections
- **`/resources/schemas/`** - JSON schemas for data validation
- **`/resources/templates/`** - Twig templates for admin interface

### Design Patterns
- **Domain-Driven Design**: Clear separation between Actions, Domain services, and Data layers
- **Repository Pattern**: Data access abstraction with JSON storage
- **Dependency Injection**: PHP-DI container with interface-based design
- **Middleware Pipeline**: Authentication, CORS, license validation, request transformation

## Key Features

- **Collection System**: 13 built-in collection types (blog, image, gallery, etc.) stored as JSON files in `/tcms-data/`
- **Twig Integration**: Custom filters/functions in `src/Domain/Twig/`, markdown processing via ParsedownExtra
- **Admin Interface**: Form builder with 20+ field types, JavaScript components in `/javascript/totalform/`
- **Build System**: ESBuild with code splitting, configuration in `esbuild.config.js`

## Development Workflow

1. **Setup**: `composer install && yarn install`
2. **Build Assets**: `bin/build-assets.sh` or `composer run esbuild`
3. **Development**: Use `bin/watch.sh` for auto-building
4. **Quality Check**: Always run `composer run test:all` before commits
5. **Testing**: Use Pest framework - tests are in `/tests/`

## Important Notes

- **Storage**: Flat-file JSON storage (no traditional database)
- **Caching**: Multi-backend Twig caching (filesystem, OPcache, Redis, Memcached)
- **Modern PHP**: Strict typing, PSR standards, PHP 8.2+ features

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

## Frontend JavaScript
- **TotalForm System**: Modular form system in `/javascript/totalform/` with field-specific components
- **Choices.js**: Enhanced select/multiselect fields with custom initialization
- **CodeMirror**: Syntax highlighting for Twig playground with localStorage persistence

## Performance & Caching

### Cache System
- **TwigCacheManager**: Multi-backend caching (filesystem, OPcache, Redis, Memcached) with auto-detection
- **Development Mode**: `isCacheEnabled` property, no file operations when `cachedir: "false"`
- **Cache Cleaner UI**: Admin interface showing cache status, hit rates, and performance recommendations
- **Container Integration**: Full dependency injection support

### Performance Optimizations
- **CollectionRefiner**: 30-70% improvement via reflection caching, optimized array operations, loose comparisons
- **CollectionSorter**: 50-70% improvement via property value caching and rule pre-processing
- **ServerChecker**: Enhanced with detailed extension info, OPcache detection improvements