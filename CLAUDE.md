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
  - **`/src/Domain/JumpStart/`** - JumpStart data import/export system with services, data objects, and factories
  - **`/src/Domain/Import/`** - CMS import systems for migrating from other platforms (Alloy, etc.)
  - **`/src/Domain/Factory/`** - Factory system for generating test data using Faker
  - **`/src/Domain/ImageWorks/`** - Complete image processing system with watermarking, font management, and caching
    - **`TextWatermarkFactory`** - Text watermark generation with TTF/OTF font support from depot
    - **`GlideFactory`** - Image manipulation and watermark application via League/Glide
    - **`ImageGenerator`** - Main service for image processing operations
  - **`/src/Domain/Property/Data/`** - Property data objects with enhanced color manipulation
    - **`ColorData`** - OKLCH color manipulation with proper hue wraparound and hex conversion
  - **`/src/Domain/Object/Service/`** - Object management services
    - **`ObjectCloner`** - Enhanced cloning with automatic onCreate/onUpdate date field handling
  - **`/src/Domain/Twig/`** - Twig templating system with adapters, extensions, and custom functions
    - **`CmsGridTokenParser`** - Parses `{% cmsgrid %}` tag syntax with `from`, `with`, `as` parameters
    - **`CmsGridNode`** - Compiles grid tags to PHP, provides `{{ item }}` and `{{ collection }}` variables
    - **`GridRenderer`** - Helper methods for `cms.grid.*` (date, tags, excerpt, price, meta)
- **`/src/Middleware/`** - HTTP middleware for auth, CORS, licensing, validation
- **`/src/Renderer/`** - Response rendering (JSON, XML, Twig, Raw)
- **`/src/Utils/`** - Utility classes for file handling, image processing, QR codes
- **`/config/`** - Hierarchical PHP configuration and route definitions
  - **`/config/routes/import.php`** - Import system routes (alloy-analyze, alloy import)
- **`/tcms-data/`** - JSON-based flat-file storage for collections
- **`/resources/schemas/`** - JSON schemas for data validation
- **`/resources/templates/`** - Twig templates for admin interface
  - **`/resources/templates/grid/`** - Default grid templates (blog.twig, feed.twig, generic.twig)
- **`/resources/docs/`** - Documentation files including JumpStart guide and field settings
- **`/resources/fonts/`** - Centralized font storage (RobotoRegular.ttf for default text watermarks)
- **`/tests/test-data/`** - Test datasets for integration testing
  - **`/tests/test-data/alloy/`** - Complete Alloy CMS test dataset (posts, embeds, droplets, image-uploads)

### Design Patterns
- **Domain-Driven Design**: Clear separation between Actions, Domain services, and Data layers
- **Repository Pattern**: Data access abstraction with JSON storage
- **Dependency Injection**: PHP-DI container with interface-based design
- **Middleware Pipeline**: Authentication, CORS, license validation, request transformation

## Key Features

- **Collection System**: 13 built-in collection types (blog, image, gallery, etc.) stored as JSON files in `/tcms-data/`
- **JumpStart System**: Data import/export system for quick project setup with predefined content structures, factory data generation, and Total CMS 1 migration
- **Import Systems**: Support for migrating from multiple CMS platforms (Total CMS 1, Alloy CMS) with job queue processing
- **ImageWorks System**: Complete image processing with text/image watermarking, custom font support, caching
- **Gallery System**: Enhanced gallery display with semantic HTML5 figure/figcaption captions support
- **Twig Integration**: Custom filters/functions in `src/Domain/Twig/`, markdown processing via ParsedownExtra
- **Grid System**: `{% cmsgrid %}` Twig tag for flexible content grids with built-in templates and helper methods in `cms.grid.*`
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

## Frontend JavaScript
- **TotalForm System**: Modular form system in `/javascript/totalform/` with field-specific components
- **Choices.js**: Enhanced select/multiselect fields with custom initialization
- **CodeMirror Bundle**: Complete local syntax highlighting solution with Twig, HTML, CSS, JS, PHP, Markdown support
- **TotalCMSCodeMirror**: Custom API for creating editors with light theme (elegant) matching dashboard design
- **Syntax Highlighting**: GitHub-inspired light theme colors, dark theme saved for future dark mode
- **Documentation Highlighting**: Auto-syntax highlighting for code blocks in documentation

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

### Emergency Cache Management
- **Automatic OPcache Clearing**: `DefaultErrorHandler` automatically clears OPcache on every error to prevent cached errors
- **No-Cache Headers**: Error responses include `Cache-Control: no-cache` headers to prevent browser/proxy caching
- **Emergency Endpoint**: `/emergency/cache/clear` provides public cache clearing when admin interface is inaccessible
- **Customer-Friendly**: No authentication required - customers can clear caches from any location without server access
- **Test Script**: `bin/test-emergency-cache.php` verifies emergency cache clearing functionality

## JumpStart System

### Overview
JumpStart is Total CMS's data import/export system for quick project setup with predefined content structures.

### Key Components
- **JumpStartData**: Core data structure containing collections, schemas, objects, and factory definitions
- **JumpStartExporter**: Exports project data to JSON format, strips media files, retains object metadata
- **JumpStartImporter**: Imports JumpStart data, processes factory definitions for test data generation
- **FactoryImporter**: Generates test data using Faker based on factory definitions

### Usage Patterns
- **Export**: Collections and objects are exported; images, files, galleries, and depot files are removed
- **Import**: Objects can be imported directly or converted to factory definitions for dynamic data generation
- **Factory System**: Use `factory` array instead of `objects` for generating test data with Faker
- **Admin Interface**: Access via `/admin/utils/jumpstart` for import/export operations
- **Project Setup**: Environment-specific demo data loading via `/admin/utils/project-setup`

### Testing
- **JumpStartBasicTest**: Core functionality tests without HTTP dependencies
- **Simplified Testing**: Removed complex HTTP endpoint tests to focus on business logic
- **24 Passing Tests**: Comprehensive test coverage for JumpStart functionality

## Twig Template System

### Global Variables
- **cms**: Primary global variable providing access to configuration, collections, and services
- **Configuration Access**: Use `cms.config('key')` not direct `config` variable (which doesn't exist)
- **Common Usage**: `cms.env` for environment, `cms.config('debug')` for debug mode

### Template Conventions
- **LazyTwigGlobal**: Proxy pattern for lazy loading of Twig global variables
- **TotalCMSTwigAdapter**: Main adapter providing CMS functionality to templates
- **Custom Extensions**: TotalCMSTwigExtension with CMS-specific filters and functions

### Development Notes
- **Template Debugging**: Use `cms.env` instead of `config.env` for environment checks
- **Error Handling**: TwigEngine provides detailed error messages in development mode
- **Performance**: Twig templates are cached in production, auto-reloaded in development

## ImageWorks System

### Overview
ImageWorks is Total CMS's comprehensive image processing system providing dynamic image manipulation, watermarking, and caching.

### Key Components
- **TextWatermarkFactory**: Generates text watermarks with custom font support
- **GlideFactory**: Integrates with League/Glide for image manipulation and watermark application
- **ImageGenerator**: Main service orchestrating image processing operations
- **ColorData**: Enhanced OKLCH color manipulation with proper hue calculations

### Text Watermarking
- **Font Support**: TTF and OTF fonts loaded from depot storage or default Roboto font
- **Configuration**: `watermarkFontsDepot` setting (default: 'watermark-fonts') for depot-based font storage
- **Features**: Text size, color, background, padding, rotation angle, transparency support
- **Caching**: Automatic watermark caching in `.watermarks` directory for performance
- **Flexible Fonts**: Supports both "FontName" and "FontName.ttf" format for font specification

### Implementation Details
- **Font Loading**: Depot fonts create temporary files for GD compatibility, cleaned up after use
- **Path Structure**: Depot fonts stored at `depot/{depotId}/depot/{filename}`
- **Cache Integration**: Watermark cache clearing integrated with main cache management system
- **Error Handling**: Graceful fallbacks to default font if depot fonts unavailable

### Usage Examples
```twig
{# Text watermark with custom font #}
{{ cms.image('image.jpg') | imageworks({
    marktext: 'Copyright 2024',
    marktextfont: 'CustomFont',
    marktextsize: 24,
    marktextcolor: 'ffffff',
    marktextalpha: 80
}) }}

{# Combined text and image watermarks #}  
{{ cms.image('photo.jpg') | imageworks({
    marktext: 'Watermark',
    markimage: 'logo.png',
    markalpha: 50
}) }}
```

### Color System Integration
- **OKLCH Support**: Full OKLCH color space manipulation with proper hue wraparound
- **Color Filters**: Twig filters for `hue()`, `lightness()`, `chroma()` adjustments
- **Hue Calculations**: Fixed 360° wraparound for color wheel operations
- **Hex Conversion**: Reliable OKLCH-to-hex conversion avoiding ColorFactory library issues

### Configuration
```php
// config/defaults.php
'imageworks' => [
    'watermarkFontsDepot' => 'watermark-fonts', // Default depot for custom fonts
    // ... other ImageWorks settings
]
```

## Object Management System

### Object Cloning
- **Enhanced Cloning**: `ObjectCloner` service with automatic date field management
- **Date Field Handling**: Objects with `onCreate` and `onUpdate` date fields automatically get current timestamps when cloned
- **Schema Integration**: Uses `SchemaFetcher` to identify date fields with special settings
- **Property Processing**: Automatic processing of date properties during clone operations

### Date Field Behavior
- **onCreate Fields**: Automatically set to current time when objects are cloned (e.g., blog post creation dates)
- **onUpdate Fields**: Automatically set to current time when objects are cloned (e.g., last modified timestamps)
- **Schema Detection**: Detects date fields from both direct type and `$ref`-based schema definitions
- **Settings Support**: Handles both top-level and nested settings for `onCreate`/`onUpdate` properties

### Implementation Example
```php
// ObjectCloner automatically handles these schema settings:
"created": {
    "$ref": "https://www.totalcms.co/schemas/properties/date.json",
    "settings": {
        "onCreate": true,
        "readonly": true
    }
},
"updated": {  
    "$ref": "https://www.totalcms.co/schemas/properties/date.json",
    "settings": {
        "onUpdate": true,
        "readonly": true
    }
}
```

## Form Field System

### Relational Options
- **Multi-field Labels**: Support for combining multiple fields in `relationalOptions` labels
- **Field Combination**: Space-separated field names in `label` parameter (e.g., `"firstName lastName"`)
- **Custom Separators**: Configurable `join` parameter for field combination (default: single space)
- **Flexible Syntax**: Supports both space-separated and comma-separated field names

### Enhanced Field Settings
- **Documentation**: Comprehensive field settings documentation in `/resources/docs/field-settings.md`
- **Examples**: Practical examples for complex relational field configurations
- **Icon System**: Updated icon reference system with font and angle icon support

## Import Systems

### Alloy CMS Import System

Total CMS includes a complete import system for migrating content from Alloy CMS, providing seamless migration of blogs, embeds, and droplets.

#### Key Components
- **AlloyImporter**: Core import service handling analysis and import of Alloy data (`src/Domain/Import/AlloyImporter.php`)
- **ImportAlloyAnalyzeAction**: API endpoint for analyzing Alloy data structure without importing (`src/Action/Import/ImportAlloyAnalyzeAction.php`)
- **ImportAlloyAction**: API endpoint for actual import processing, queues items via job system (`src/Action/Import/ImportAlloyAction.php`)
- **Admin Interface**: Project Setup page integration with user-friendly import forms

#### Supported Content Types
1. **Blog Posts**: Markdown files with YAML front matter
   - Parses metadata: title, author, category, date, draft status, tags
   - Converts markdown content to HTML
   - Handles featured images and media references
   - Preserves publication dates and author attribution

2. **Embeds**: Markdown content blocks
   - Converts to styled text collection entries
   - Preserves formatting and structure
   - Maintains content relationships

3. **Droplets**: Text and image content snippets  
   - Handles both text and image droplet types
   - Converts image URLs to Total CMS image references
   - Preserves content structure and metadata

#### Technical Implementation
- **YAML Front Matter Parsing**: Uses `webuni/front-matter` library for robust metadata extraction
- **Markdown Processing**: Parsedown conversion with HTML sanitization
- **Job Queue Integration**: Background processing for large imports without timeouts
- **Progress Tracking**: Real-time analysis and import progress feedback
- **Error Handling**: Graceful handling of missing directories and malformed content
- **PHPStan Level 8 Compliant**: Full type safety and static analysis compliance

#### Usage Workflow
1. **Access**: Navigate to `/admin/utils/project-setup` → "Other Supported Import Tools" → "Alloy"
2. **Configure**: Specify folder paths for blogs, image uploads, embeds, and droplets
3. **Analyze**: Preview import data with detailed counts and content structure
4. **Import**: Queue all content for background processing via job system
5. **Monitor**: Track progress through Job Queue Manager

#### API Endpoints
```php
POST /import/alloy-analyze  // Analyze Alloy data structure
POST /import/alloy          // Queue import via job system
```

#### Testing
- **Comprehensive Test Coverage**: 10 passing tests covering API validation, data processing, error handling, and admin interface
- **Test Data**: Complete Alloy test dataset with 37 blogs, 66 embeds, 57 droplets
- **Integration Testing**: Full workflow testing from analysis to import completion

## Gallery System Enhancements

### Semantic HTML5 Captions

Total CMS galleries now support semantic HTML5 figure/figcaption elements for improved accessibility and SEO.

#### Implementation
- **TotalCMSTwigAdapter::gallery()**: Enhanced with `captions` option for displaying alt text as visible captions
- **Semantic HTML**: Uses proper `<figure>` and `<figcaption>` elements when captions are enabled
- **Backwards Compatible**: Existing galleries continue to work without changes
- **Security**: Captions are HTML-escaped to prevent XSS attacks

#### Usage
```twig
{# Enable captions with semantic HTML5 #}
{{ cms.gallery('gallery-id', {w: 300, h: 200}, {}, {captions: true}) }}

{# Works with all existing options #}
{{ cms.gallery('mygallery', {w: 150, h: 150}, {w: 800}, {
    captions: true,
    maxVisible: 6,
    viewAllText: 'View All Photos'
}) }}
```

#### Generated HTML Structure
**Without captions (unchanged):**
```html
<div class="cms-gallery">
  <a href="full-image.jpg">
    <img src="thumb.jpg" alt="Mountain landscape">
  </a>
</div>
```

**With captions (semantic HTML5):**
```html
<div class="cms-gallery">
  <figure class="cms-gallery-item">
    <a href="full-image.jpg">
      <img src="thumb.jpg" alt="Mountain landscape">
    </a>
    <figcaption class="cms-gallery-caption">Mountain landscape</figcaption>
  </figure>
</div>
```

#### Benefits
- **Accessibility**: Screen readers understand the image-caption relationship
- **SEO**: Search engines better understand content structure
- **Semantic HTML**: Proper HTML5 elements designed for images with captions
- **CSS Styling**: Easier styling with semantic selectors like `figure.cms-gallery-item`