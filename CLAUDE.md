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
Extensive Twig templating with custom filters and functions in `src/Domain/Twig/`. The TwigEngine provides template rendering with caching.

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