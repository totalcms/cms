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

**Documentation Priorities** (Target: 3.1 Launch):
- Comprehensive user guides for all collection types
- Template development documentation with practical examples
- Field settings reference with visual examples
- Import/export workflows including JumpStart system
- Best practices for common use cases
- Video tutorials for key features
- Migration guides from other CMS platforms

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
```bash
# Frontend asset building (primary development command)
composer run esbuild
yarn build

# Development with file watching (typically runs in background)
bin/watch.sh

# Full application build (manual release builds only)
composer run build

# Create distribution bundle (manual release builds only)
composer run bundle

# Note: Scripts in /bin/ are primarily for manual release builds.
# During development, use composer commands or keep watch.sh running.
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
- **Caching**: Multi-backend Twig caching with APCu-first priority (APCu, Redis, Memcached, filesystem, OPcache)
- **Modern PHP**: Strict typing, PSR standards, PHP 8.2+ features with PHP 8.4 compatibility
- **Enhanced Libraries**: Custom couleur fork with OKLCH improvements, native EXIF processing

## Recent Major Updates (2024-2025)

### Cache Management Enhancements (Latest)
- **Detailed Status Arrays**: `CacheManager::clearAllCaches()` returns detailed per-backend status instead of boolean
- **Emergency Cache Improvements**: Enhanced debugging with clear success/failure reasons for each cache backend
- **Better Observability**: All cache operations return structured data for easier troubleshooting

### Memory Management & Performance
- **Streaming JSON Export**: JumpStart exports use streaming to handle large datasets without memory exhaustion
- **Incremental Processing**: Objects written individually with immediate memory cleanup via `unset()`
- **Helper Patterns**: Reusable `encodeJson()` pattern for JSON operations with proper error handling
- **Large Dataset Support**: Can export thousands of objects within standard PHP memory limits

### JumpStart System Enhancements
- **Template Support**: Full import/export of custom Twig templates alongside collections and objects
- **Streaming Architecture**: Memory-efficient export using `streamJsonToFile()` for large projects
- **Enhanced Export Order**: schemas → collections → templates → objects → factory for proper dependencies

### Admin Interface Improvements
- **SimpleForm Extensibility**: Button class customization without modifying core form builder
- **Method Attribute Handling**: TotalForm JavaScript properly reads `data-method` for DELETE/PUT/PATCH operations
- **CSRF Integration**: Automatic CSRF token injection for POST/PUT/DELETE/PATCH methods
- **Settings Forms**: Standardized `cms-save` class on save buttons for consistent styling

### License System Modernization
- **Simplified Data Structure**: Reduced from 15+ fields to 8 essential fields for performance
- **CamelCase API**: Consistent camelCase throughout API responses and JWT tokens
- **Service Architecture**: JWT validation moved from middleware to dedicated service
- **Type Safety**: Config class protected against invalid configuration types
- **Deep Merge Configuration**: Users can override specific nested settings without duplicating entire arrays

### Configuration System Enhancements
- **Deep Merge Support**: `deepMergeArrays()` function enables granular configuration overrides
- **Type-Safe Config**: All array properties protected with `is_array()` validation
- **Backward Compatibility**: Legacy `$settings[]` syntax still works alongside new return-array style
- **User-Friendly**: tcms.php sample updated with examples and best practices

### APCu Cache Integration
- **Primary Cache Backend**: APCu now first in priority for optimal single-server performance
- **Zero Configuration**: Works immediately with APCu extension, no external services required
- **UI Integration**: Server Checker and Cache Management fully support APCu with detailed statistics
- **Performance Optimized**: Cache priority reflects real-world single-server deployment patterns

### PHP 8.4 Compatibility & EXIF Enhancements
- **ImageMetaReader**: Native PHP EXIF implementation replacing lychee-org/php-exif
- **Automatic Metadata**: Image uploads auto-populate alt text and tags from EXIF/XMP/IPTC data
- **Enhanced Processing**: XMP lens extraction, location data, keyword processing, GPS coordinate formatting
- **Schema Compliance**: All metadata returned in proper string formats for validation

### Enhanced Color System
- **Couleur Library Fork**: Custom fork with OKLCH hue wraparound mathematics
- **ColorData Integration**: Simplified using enhanced library functions
- **Proper Color Operations**: Fixed 360° hue calculations for color wheel operations

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

When working with large datasets, Total CMS employs several patterns to prevent memory exhaustion:

#### Streaming Pattern
Instead of loading entire datasets into memory, use streaming to process data incrementally:

```php
// BAD: Loads everything into memory
$json = json_encode($largeArray);
file_put_contents($file, $json);

// GOOD: Stream data incrementally
$fileHandle = fopen($file, 'w');
foreach ($largeArray as $item) {
    fwrite($fileHandle, json_encode($item));
    unset($item); // Free memory immediately
}
fclose($fileHandle);
```

#### Helper Method Pattern
Create reusable helpers that handle errors and prevent false returns from entering the data stream:

```php
// Helper method with error checking
private function encodeJson($data, int $flags = 0): string
{
    $json = json_encode($data, $flags);
    if ($json === false) {
        throw new \RuntimeException('Failed to encode data to JSON: ' . json_last_error_msg());
    }
    return $json;
}
```

#### Immediate Cleanup Pattern
Free memory immediately after processing each item in large loops:

```php
foreach ($largeCollection as $item) {
    $processedItem = $this->processItem($item);
    $this->saveItem($processedItem);

    // Free memory immediately
    unset($item, $processedItem);
}
```

#### Temporary File Pattern
Use PHP's `tmpfile()` for intermediate storage to avoid keeping data in memory:

```php
// Create temporary file for streaming
$tempFile = tmpfile();
if ($tempFile === false) {
    throw new \RuntimeException('Failed to create temporary file');
}

// Stream data to temp file
$this->streamDataToFile($tempFile);
rewind($tempFile);

// Use temp file as response (auto-cleaned on close)
return $response->withBody(Stream::create($tempFile));
```

#### Real-World Example: JumpStart Export
See `JumpStartData::streamJsonToFile()` for a complete implementation of streaming JSON export that:
- Writes JSON structure incrementally
- Processes objects one at a time
- Uses `unset()` to free memory after each object
- Handles errors with proper exceptions
- Can export thousands of objects within standard PHP memory limits

**Key Principle**: Always consider memory usage when working with collections, exports, or any operation that processes multiple items. Default to streaming patterns for any dataset that could potentially grow large.

## Frontend JavaScript
- **TotalForm System**: Modular form system in `/javascript/totalform/` with field-specific components
- **Choices.js**: Enhanced select/multiselect fields with custom initialization
- **CodeMirror Bundle**: Complete local syntax highlighting solution with Twig, HTML, CSS, JS, PHP, Markdown support
- **TotalCMSCodeMirror**: Custom API for creating editors with light theme (elegant) matching dashboard design
- **Syntax Highlighting**: GitHub-inspired light theme colors, dark theme saved for future dark mode
- **Documentation Highlighting**: Auto-syntax highlighting for code blocks in documentation

## Performance & Caching

### Cache Architecture

Total CMS implements a multi-backend caching system with APCu-first priority for optimal single-server performance.

**Cache Priority Order**: APCu → Redis → Memcached → Filesystem (OPcache runs automatically for PHP bytecode)

#### Multi-Backend Support
- **APCuService**: Zero-config in-memory caching with prefix support (`tcms_` default)
- **RedisService**: Network-based caching for distributed deployments
- **MemcachedService**: Alternative distributed caching backend
- **FilesystemService**: File-based fallback caching always available
- **TwigCacheManager**: Automatic backend detection with graceful fallbacks
- **Container Integration**: Full dependency injection support for all cache services

#### APCu as Primary Backend
- **Performance**: Faster than Redis for single-server deployments (no network overhead)
- **Zero Configuration**: Works immediately when APCu extension is installed
- **Resource Efficiency**: Lower memory footprint than separate Redis daemon
- **Shared Hosting Friendly**: Pre-installed on most hosting providers
- **Pattern Clearing**: Efficient cache pattern matching via APCu iterators
- **Statistics**: Hit rates, memory usage, entry counts with 1-decimal precision

#### Configuration
```php
// config/defaults.php
'cache' => [
    'apcu' => [
        'enabled' => true,
        'prefix'  => 'tcms_',
    ],
    'redis' => [
        'enabled' => false,
        'host'    => '127.0.0.1',
        'port'    => 6379,
    ],
    // Other cache backends available for advanced deployments
],
```

#### Cache Management Features
- **Development Mode**: `isCacheEnabled` property, no file operations when `cachedir: "false"`
- **Admin UI**: Cache status dashboard with hit rates, memory usage, and performance recommendations
- **Detailed Status**: `clearAllCaches()` returns per-backend status arrays for debugging
- **Server Checker**: Extension detection and functionality testing for all backends
- **Clear Operations**: Individual backend clearing or "Clear All Caches" convenience method

#### Emergency Cache Management
- **Emergency Endpoint**: `/emergency/cache/clear` provides public cache clearing when admin interface is inaccessible
- **Detailed Response**: Returns structured status array showing success/failure for each cache backend
- **Customer-Friendly**: No authentication required - customers can self-service cache issues
- **Automatic OPcache Clearing**: `DefaultErrorHandler` clears OPcache on errors to prevent cached errors
- **No-Cache Headers**: Error responses include `Cache-Control: no-cache` to prevent browser/proxy caching

### Performance Optimizations
- **CollectionRefiner**: 30-70% improvement via reflection caching, optimized array operations, loose comparisons
- **CollectionSorter**: 50-70% improvement via property value caching and rule pre-processing
- **ServerChecker**: Enhanced with detailed extension info, APCu + OPcache detection, cache functionality testing
- **Streaming Export**: Memory-efficient JSON streaming for large dataset operations (JumpStart)

## JumpStart System

### Overview
JumpStart is Total CMS's data import/export system for quick project setup with predefined content structures. It supports streaming exports for large datasets and includes full template support.

### Key Components
- **JumpStartData**: Core data structure containing collections, schemas, objects, templates, and factory definitions
- **JumpStartExporter**: Exports project data with streaming support for memory efficiency
- **JumpStartImporter**: Imports JumpStart data, processes factory definitions for test data generation
- **FactoryImporter**: Generates test data using Faker based on factory definitions

### Data Structure
JumpStart files contain the following sections (exported/imported in this order):
1. **Schemas**: Custom schema definitions
2. **Collections**: Both reserved collections (by type) and custom collections (full definition)
3. **Templates**: Custom Twig templates (reserved templates excluded)
4. **Objects**: Actual content objects with media references normalized
5. **Factory**: Factory definitions for generating test data

### Export Features
- **Streaming Architecture**: Uses `streamJsonToFile()` to handle large datasets without memory exhaustion
- **Memory Efficiency**: Objects written incrementally with immediate memory cleanup
- **Media Handling**: Images, files, galleries, and depot files are normalized (not included in export)
- **Template Export**: Custom Twig templates exported with full content
- **Metadata Preservation**: Object structure and relationships preserved

### Import Features
- **Dependency Order**: Imports in correct order to ensure references exist (schemas → collections → templates → objects → factory)
- **Template Recreation**: Custom templates recreated from export data
- **Factory Processing**: Dynamic data generation using Faker for factory definitions
- **Object Validation**: Checks for existing objects to avoid duplicates
- **Error Handling**: Graceful handling with detailed error messages per item

### Usage Patterns

**Export Process:**
```php
$exporter = $container->get(JumpStartExporter::class);
$exporter->setMetadata('Project Name', 'Description');
$jumpstartData = $exporter->exportCurrentData();

// Streaming export for large datasets
$fileHandle = fopen($exportFile, 'w');
$jumpstartData->streamJsonToFile($fileHandle);
fclose($fileHandle);
```

**Import Process:**
```php
$importer = $container->get(JumpStartImporter::class);
$result = $importer->importFromFile($jumpstartFile);

// Check results
if ($result['success']) {
    echo "Imported: " . $result['summary']['objects_created'] . " objects\n";
} else {
    print_r($result['errors']);
}
```

**Factory Definitions:**
Instead of exporting actual objects, use factory definitions for dynamic test data:
```json
{
    "factory": [
        {
            "collection": "blog",
            "count": 50,
            "data": {
                "title": "sentence",
                "content": "paragraphs:5",
                "author": "name",
                "featured": "image:1200:800"
            }
        }
    ]
}
```

### Admin Interface
- **Export**: `/admin/utils/jumpstart` - Export current project data
- **Import**: `/admin/utils/jumpstart` - Import JumpStart files
- **Project Setup**: `/admin/utils/project-setup` - Load demo data or import from other CMS platforms

### Memory Management
The streaming export system handles large projects efficiently:
- Objects streamed individually to disk
- Memory freed immediately after each object
- Can export thousands of objects within standard PHP memory limits
- See "Memory Management Best Practices" section for implementation details

### Testing
- **JumpStartBasicTest**: Core functionality tests without HTTP dependencies
- **Simplified Testing**: Focused on business logic for reliable test results
- **Comprehensive Coverage**: Tests for export, import, streaming, templates, and factory generation

### Best Practices
- Use streaming export for projects with more than 100 objects
- Export templates alongside collections for complete project snapshots
- Use factory definitions for test/demo data instead of real content
- Always test imports on development environment first
- Check import results array for detailed success/error information

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
- **ImageMetaReader**: PHP 8.4-compatible EXIF metadata extraction system

### EXIF Metadata System
- **Native PHP Implementation**: Replaces lychee-org/php-exif for PHP 8.4 compatibility
- **Comprehensive Extraction**: EXIF, XMP, and IPTC metadata from multiple sources
- **Automatic Population**: Image upload auto-populates alt text and tags from metadata
- **Enhanced Data Processing**:
  - XMP lens extraction from `aux:Lens` field
  - IPTC location data (city, state, country, sublocation)
  - Keyword extraction from multiple metadata sources
  - GPS coordinate parsing with proper string formatting
  - Fraction cleanup (removes "/1" denominators for cleaner display)
- **Smart Data Preservation**: Existing alt text and tags preserved during re-uploads
- **Schema Compliance**: All GPS coordinates returned as strings to match validation requirements

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
- **Enhanced Couleur Library**: Fork with improved OKLCH color space support at `/Users/joeworkman/Developer/forks/couleur`
- **Hue Wraparound**: Proper 360° hue mathematics for color wheel operations
- **OKLCH Support**: Full OKLCH color space manipulation with proper hue calculations
- **Color Filters**: Twig filters for `hue()`, `lightness()`, `chroma()` adjustments
- **Hex Conversion**: Reliable OKLCH-to-hex conversion avoiding ColorFactory library issues
- **ColorData Integration**: Uses enhanced couleur library functions instead of duplicating logic

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

## SimpleForm System

### Overview
SimpleForm provides a lightweight form builder for basic admin operations like cache clearing, settings forms, and simple actions that don't require full TotalForm complexity.

### Key Features
- **Minimal Configuration**: Create forms with just route, method, and button label
- **CSRF Protection**: Automatic CSRF token injection for POST/PUT/DELETE/PATCH methods
- **Method Override**: Supports full REST methods (GET, POST, PUT, DELETE, PATCH)
- **AJAX Support**: Configurable AJAX submission with optional page refresh
- **Button Customization**: Extensible button classes without modifying core class
- **Data Attributes**: Automatic generation of data attributes for JavaScript handling

### Constructor Parameters
```php
public function __construct(
    private string $api,                        // API identifier for routing
    private string $route,                      // Form submission route
    private string $method = 'POST',            // HTTP method
    private string $label = 'Submit',           // Button label
    private string $class = '',                 // Form CSS classes
    private bool $refresh = false,              // Refresh page after submission
    private bool $ajax = true,                  // Enable AJAX submission
    private ?CSRFTokenManager $csrfManager = null, // Optional CSRF manager
    private string $buttonClass = '',           // Additional button classes
)
```

### Usage Examples

**Basic Form:**
```php
$form = new SimpleForm(
    api: '/admin/cache',
    route: '/admin/cache/clear',
    method: 'DELETE',
    label: 'Clear Cache'
);
echo $form->build();
```

**Settings Form with Custom Button Class:**
```php
$form = new SimpleForm(
    api: '/admin/settings',
    route: '/admin/settings/general',
    method: 'POST',
    label: 'Save Settings',
    refresh: true,
    buttonClass: 'cms-save'
);
echo $form->build($formFields);
```

**Non-AJAX Form:**
```php
$form = new SimpleForm(
    api: '/admin/export',
    route: '/admin/export/data',
    method: 'GET',
    label: 'Download Export',
    ajax: false
);
```

### JavaScript Integration
SimpleForm works with `totalform.js` which automatically:
- Reads `data-method` attribute for proper HTTP method handling
- Handles AJAX submissions when `data-ajax="true"`
- Refreshes page when `data-refresh="true"`
- Processes CSRF tokens from the form

### TotalFormFactory Integration
`TotalFormFactory::settings()` uses SimpleForm with standardized settings:
```php
return $this->simple('/admin/settings/' . $section, $formfields, [
    'method'      => 'POST',
    'label'       => 'Save Settings',
    'refresh'     => true,
    'class'       => 'help-on-hover help-box',
    'buttonClass' => 'cms-save',
]);
```

### Generated HTML Structure
```html
<form class="simple-form totalform {custom-classes}"
      data-route="{route}"
      data-method="{method}"
      data-api="{api}"
      data-refresh="{true|false}"
      data-ajax="{true|false}">

    <!-- CSRF token (auto-injected for POST/PUT/DELETE/PATCH) -->
    <input type="hidden" name="_csrf" value="{token}">

    <!-- Form content -->
    {content}

    <!-- Submit button -->
    <div class="form-inline-fields">
        <button type="submit" class="dash-button {buttonClass}">{label}</button>
    </div>
</form>
```

### Best Practices
- Use SimpleForm for single-action forms (clear cache, save settings, delete item)
- Use full TotalForm for complex multi-field forms with validation
- Always provide CSRF manager for forms that modify data
- Use `buttonClass` for styling instead of modifying SimpleForm class
- Set `ajax: false` for downloads or file operations

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
- **Comprehensive Test Coverage**: Full test suite covering API validation, data processing, error handling, and admin interface
- **Test Data**: Complete Alloy test dataset with extensive blogs, embeds, and droplets for realistic testing
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

## License System (Updated 2024)

### Simplified License Data Structure

The license system has been streamlined to focus on essential validation data while leaving detailed management to the license server.

#### Core LicenseData Fields
```php
readonly class LicenseData {
    public bool $valid;              // License validation status
    public bool $trial;              // Is this a trial license
    public string $domain;           // Licensed domain
    public string $edition;          // License edition
    public string $message;          // Error/status messages
    public ?string $validationToken; // JWT token for additional validation
    public bool $updatesValid;       // Update subscription status
    public ?int $trialDaysRemaining; // Days remaining for trials
}
```

#### Key Architecture Changes
- **Simplified Data**: Reduced from 15+ fields to 8 essential fields
- **CamelCase API**: All API responses and JWT properties use camelCase
- **Service Separation**: JWT validation moved from middleware to `LicenseValidator`
- **Cache Management**: TTL constant moved to `LicenseData` class
- **Type Safety**: Config class protected against invalid configuration types

#### License Validation Flow
1. **Middleware Check**: `LicenseValidationMiddleware` handles HTTP validation flow
2. **Service Validation**: `LicenseValidator` performs API calls and JWT validation
3. **Caching**: Multi-backend cache with 24-hour TTL managed by `LicenseData::CACHE_TTL`
4. **Status Display**: `LicenseStatus` provides sidebar status with progressive trial urgency

#### JWT Token Validation
- **Location**: Handled in `LicenseValidator::validateJwtToken()`
- **Format**: CamelCase properties (`expiresAt` instead of `expires_at`)
- **Security**: Shared secret validation with expiration checking
- **Graceful Degradation**: Falls back to standard `exp` claim for compatibility

#### Cache System Integration
- **Priority**: APCu > Redis > Memcached > Filesystem
- **Domain-Specific**: Cache keys include domain for multi-site deployments
- **License-Specific**: `storeLicenseData()` bypasses dev mode restrictions
- **Emergency Clearing**: `/emergency/cache/clear` endpoint for customer self-service

## Configuration System Enhancements

### Deep Merge Configuration

Total CMS now supports deep merging of configuration arrays, allowing users to override specific nested settings without replacing entire configuration structures.

#### Usage in tcms.php
```php
// Recommended: Return array for deep merging
return [
    'cache' => [
        'redis' => [
            'password' => 'your_password', // Only override the password
            // Other redis settings remain from defaults
        ],
        'memcached' => [
            'enabled' => false, // Disable specific backends
        ],
    ],
    'imageworks' => [
        'watermarksGallery' => 'custom-watermarks',
        // Other imageworks settings preserved
    ],
];

// Legacy style still works but not recommended:
// $settings['cache']['redis']['password'] = 'password';
```

#### Deep Merge Implementation
- **Function**: `deepMergeArrays()` in `config/settings.php`
- **Recursive**: Handles nested arrays at any depth
- **Precedence**: User settings override defaults
- **Backward Compatible**: Legacy `$settings[]` syntax still works

#### Type Safety Improvements
- **Config Class**: All array properties protected with type checking
- **Validation**: `is_array()` checks prevent type violations
- **Fallbacks**: Invalid types converted to empty arrays
- **PHPStan Compliance**: Maintains Level 8 static analysis compliance