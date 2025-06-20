# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Total CMS is a modern PHP-based Content Management System using flat-file JSON storage. Built with Slim 4 framework, it provides a RESTful API with Twig templating and a comprehensive admin interface.

## Related Projects

### Total CMS License API (`/Users/joeworkman/Websites/license.totalcms.co`)
- **Purpose**: License management API that handles validation, trial management, and domain management
- **Integration**: Total CMS core makes HTTP calls to license API for validation and feature access control
- **Key Endpoints**: `/license/validate`, `/license/{key}/domain`, `/trial`
- **Shared Architecture**: Both projects use Slim 4, PHP-DI, similar domain-driven design patterns

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

## Key Features to Understand

### Collection System
The CMS uses 13 built-in collection types (blog, image, gallery, etc.) with schema-driven object structure. Collections are stored as JSON files in `/tcms-data/`.

### Twig Integration
Extensive Twig templating with custom filters and functions in `src/Domain/Twig/`. The TwigEngine provides template rendering with caching and markdown processing via ParsedownExtra.

### Admin Interface
Form builder with 20+ field types, data tables with filtering/sorting, and job queue management. JavaScript components are in `/javascript/totalform/`.

### Build System
ESBuild handles modern JavaScript/CSS bundling with code splitting. Configuration in `esbuild.config.js` with SCSS preprocessing.

## Development Workflow

1. **Setup**: `composer install && yarn install`
2. **Build Assets**: `bin/build-assets.sh` or `composer run esbuild`
3. **Development**: Use `bin/watch.sh` for auto-building
4. **Quality Check**: Always run `composer run test:all` before commits
5. **Testing**: Use Pest framework - tests are in `/tests/`

## Important Notes

- **Storage**: Uses flat-file JSON storage instead of traditional databases
- **Caching**: Twig templates are cached, cleared programmatically via TwigCacheCleaner
- **API-First**: RESTful design with comprehensive OpenAPI documentation
- **Modern PHP**: Strict typing, PSR standards, and PHP 8.2+ features throughout
- **Asset Processing**: Images processed with intervention/image, supports various formats

## Security Architecture

### Session Management
- **Primary Pattern**: Use `Odan\Session\PhpSession` for all session operations instead of direct `$_SESSION` access
- **CSRF Protection**: `CSRFTokenManager` uses PhpSession for token storage and validation
- **Container Configuration**: PhpSession is properly configured in `config/container.php` with session config

### HTML Sanitization
- **HTMLSanitizer**: Located in `src/Utils/HTMLSanitizer.php`, handles XSS prevention
- **Usage**: All `preg_replace()` calls must be cast to `(string)` for PHPStan Level 8 compliance
- **Configuration**: Supports different sanitization levels (rich content vs strict content)

### CSRF Protection
- **CSRFTokenManager**: Uses PhpSession, generates cryptographically secure tokens
- **CSRFProtectionMiddleware**: Validates tokens from POST data, headers, or query parameters
- **Token Sources**: POST data > X-CSRF-Token header > query parameters (in priority order)
- **Integration**: Both classes are configured in container and work together seamlessly

### SVG Sanitization
- **SvgData**: Property data class that automatically sanitizes SVG content using `enshrined/svg-sanitize`
- **Security Features**: Removes script tags, event handlers, foreign objects, and blocks remote references
- **Validation**: Verifies content is valid SVG after sanitization, throws exception if invalid
- **Configuration**: Sanitizer configured with `removeRemoteReferences(true)` for security
- **User Control**: Sanitization can be disabled via `svgclean` setting (`['svgclean' => false]`), enabled by default

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

## Recent Architecture Changes (2025-06-19)

### CSRF Token Management Migration
- **Change**: Migrated CSRFTokenManager from direct `$_SESSION` access to `Odan\Session\PhpSession`
- **Reason**: Better abstraction, cleaner dependency injection, consistent with Total CMS patterns
- **Files Updated**:
  - `src/Utils/CSRFTokenManager.php` - Constructor now takes PhpSession
  - `config/container.php` - Updated CSRFTokenManager factory to inject PhpSession
  - `tests/Security/CSRFTokenManagerTest.php` - Updated to use PhpSession in tests
  - `tests/Security/CSRFProtectionMiddlewareTest.php` - Updated test setup for PhpSession

### PHPStan Level 8 Fixes
- **HTMLSanitizer**: Fixed type annotations, cast all `preg_replace()` calls to `(string)`
- **StringData**: Fixed HTMLSanitizer instantiation (no constructor parameters)
- **FileUploadValidator**: Fixed null handling and unnecessary null coalescing operators
- **All Security Classes**: Added proper type annotations and null safety checks

### SVG Sanitization Implementation (2025-06-19)
- **Change**: Added comprehensive SVG sanitization to SvgData using `enshrined/svg-sanitize`
- **Security**: Automatically removes XSS vectors like script tags, event handlers, foreign objects
- **Integration**: Seamless integration in constructor - all SVG content is sanitized before validation
- **Files Updated**:
  - `src/Domain/Property/Data/SvgData.php` - Added sanitization with security configuration
  - `tests/Security/SvgSanitizationTest.php` - Comprehensive test suite for SVG security
- **Configuration**: Sanitizer configured to block remote references and preserve readability

## Frontend JavaScript Architecture (2025-06-20)

### TotalForm System
- **Location**: `/javascript/totalform/` - Modular JavaScript form system with field-specific components
- **Pattern**: Each field type has its own JS class (identifier.js, list.js, etc.) extending base functionality
- **Integration**: Fields are initialized automatically, with edit mode detection for pre-populated forms
- **Key Components**:
  - `identifier.js` - Handles slug generation with custom slugify configuration
  - `list.js` - Integrates with Choices.js for select/multiselect functionality
  - Form validation with disabled field handling
  - Data serialization for form duplication and AJAX submissions

### Choices.js Integration
- **Library**: Used extensively for enhanced select/multiselect fields in admin interface
- **Configuration**: Custom initialization with `selectedValues()` method for proper label display
- **Data Flow**: PHP FormField classes generate proper option structures, JS handles presentation
- **MaxItemCount**: Special handling to prevent notification popups on page load when limits reached

### CodeMirror Integration
- **Usage**: Syntax highlighting for Twig playground and HTML output display
- **Configuration**: Auto-expanding editors using `viewportMargin: Infinity` for seamless UX
- **Modes**: Twig, HTML/XML modes with custom color schemes for better readability
- **Features**: localStorage persistence, keyboard shortcuts, automatic formatting

### Schema Management Frontend
- **Duplication**: JavaScript-based schema duplication using form data serialization
- **Pattern**: Capture current form state, POST to new schema endpoint with JSON-encoded data
- **Integration**: Works with TotalForm system to extract all field values including complex nested data

## Twig Markdown Integration (2025-06-20)

### ParsedownExtra Integration
- **Implementation**: `src/Domain/Twig/ParsedownMarkdown.php` - Adapter implementing `MarkdownInterface`
- **Configuration**: ParsedownExtra with safe mode enabled and breaks enabled for security
- **TwigEngine Setup**: Runtime loader provides ParsedownMarkdown instance to MarkdownRuntime
- **Usage**: Twig `|markdown` filter processes content through ParsedownExtra instead of CommonMark
- **Dependencies**: `erusev/parsedown-extra` package provides enhanced markdown capabilities

### Twig Playground Enhancement
- **Location**: `resources/templates/admin/utils/twig-playground.twig`
- **Features**: 
  - CodeMirror syntax highlighting for both Twig input and HTML output
  - Auto-expanding editors without scrollbars using `viewportMargin: Infinity`
  - localStorage persistence for Twig code between sessions
  - Keyboard shortcuts (Ctrl/Cmd+Enter) for quick template rendering
  - HTML beautification with proper syntax highlighting using HTML5 orange colors
- **JavaScript Integration**: Seamless integration with CodeMirror modes and localStorage API