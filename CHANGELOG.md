# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

## [3.0.36] - 2025-08-11

### Added
- **Gallery System**: Added `class` option to `cms.gallery()` function for custom CSS classes
  - Allows adding custom classes to the gallery wrapper while preserving the default `cms-gallery` class
  - Supports multiple classes via space-separated string (e.g., `class: 'featured-gallery large-gallery'`)
  - Works seamlessly with all existing gallery options (captions, maxVisible, etc.)

### Enhanced
- **Deck System**: Improved default value handling for deck items
  - Fixed default values not being applied when creating new deck items
  - Enhanced `DeckItem` form rendering to properly pass schema defaults to form fields
  - Better integration between deck schemas and form field default value system
- **Data Validation**: Strengthened deck schema compatibility checking
  - Added 'deck' type to incompatible property types to prevent nested deck structures
  - Enhanced PropertyFactory validation with clear error messages for incompatible deck properties
  - Better error handling when deck schemas contain unsupported field types

### Fixed
- **Forms**: Resolved form error display issues
  - Fixed form errors not displaying properly in certain scenarios
  - Improved error feedback for better user experience
- **Imports**: Enhanced Alloy CMS import functionality
  - Improved blog content import with better content processing
  - Enhanced styled text handling during import operations
- **Browser Compatibility**: Fixed HTML datetime input format issues
  - Resolved "value does not conform to required format" console warnings for date fields
  - Added proper format parameter to `DateData::cleanDate()` method for HTML form compatibility
  - Updated `DateField` and `DatetimeField` classes to use browser-compatible formats
- **Documentation**: Fixed broken documentation links

## [3.0.35] - 2025-08-08

### Added
- **NEW**: Deck field system - powerful structured object management
  - Full CRUD operations with dedicated UI for deck items
  - Advanced ID synchronization between deck items and dialog fields
  - Support for numeric IDs (e.g., "1", "123") alongside traditional identifiers
  - Real-time validation with comprehensive error handling
  - JavaScript integration with sorting, duplication, and validation
  - Schema compatibility checking with built-in warnings
- **NEW**: Alloy CMS import system for seamless migration
  - Complete import functionality from Alloy CMS platforms
  - Pre-import data analysis to identify compatible content structures
  - Background job queue processing for large imports
  - Streamlined admin interface for managing import operations
- **NEW**: Enhanced gallery system with semantic HTML5
  - All galleries now use proper `<figure>` and `<figcaption>` elements
  - Optional image captions below thumbnails via `captions` option
  - Better accessibility with semantic HTML structure
  - Enhanced LightGallery integration with proper data attributes

### Enhanced
- **Forms**: Modern layout improvements
  - New `useFormGrid` option for contemporary form layouts
  - Multi-field label support in relational options with configurable separators
  - Enhanced inline form fields with improved styling
  - Better field validation with real-time feedback
- **Development Experience**: Improved developer tools
  - Enhanced development mode with intelligent cache management
  - Fixed Twig playground HTML code view scrolling issues
  - Better error display and debugging capabilities
  - Comprehensive schema categorization system
- **API**: New utility methods and endpoints
  - Enhanced file upload capabilities including URL-based uploads
  - Complete deck management API with CRUD operations
  - Improved utility methods for common development tasks
  - Better error handling across all endpoints

### Fixed
- **Gallery & Media**: Resolved display and functionality issues
  - Fixed LightGallery `data-src` attribute placement for proper lightbox operation
  - Resolved maxVisible feature compatibility with new semantic HTML structure
  - Enhanced "View All" indicator placement within figure elements
  - Improved gallery item structure consistency
- **Deck System**: Comprehensive validation and UI fixes
  - Fixed numeric ID validation to allow flexible naming patterns
  - Resolved deck item ID synchronization issues with autogen fields
  - Fixed deck validation regex to properly handle mixed patterns
  - Enhanced deck item duplication and deletion workflows
- **Form & Field Operations**: Various field-specific improvements
  - Resolved tag field drag-and-drop functionality
  - Fixed form submission issues in import workflows
  - Better field synchronization across complex forms
  - Improved error handling in form validation

### Changed
- **BREAKING**: Gallery HTML structure now always uses `<figure>` elements
  - May require CSS updates for custom gallery styling
  - Improved semantic structure benefits accessibility and SEO
- **Deck Validation**: More permissive numeric ID validation
  - Now allows mixed patterns like "123feature" for greater flexibility
  - Maintains backward compatibility while expanding naming options
- **Performance**: Enhanced cache management
  - Better development mode detection and cache handling
  - Improved memory management for large datasets
  - Optimized collection processing and filtering

### Developer Notes
- Updated CLAUDE.md with comprehensive deck system documentation
- Enhanced import system guides with step-by-step migration instructions
- Improved API reference documentation with new endpoints
- Added practical examples for deck usage and gallery integration

## [3.0.34] - 2025-07-26

### Added
- **NEW**: Text watermarking system with custom font support
  - Support for TTF and OTF font files from depot storage
  - Configurable `watermarkFontsDepot` setting (default: 'watermark-fonts')
  - Text size, color, background, padding, and rotation angle support
  - Automatic caching for improved performance
- **NEW**: Enhanced object cloning functionality
  - Objects with `onCreate` date fields now get current timestamp when cloned
  - Objects with `onUpdate` date fields now get current timestamp when cloned
  - Automatic property processing for date field management
- **NEW**: Multi-field relational options documentation
  - Support for combining multiple fields in `relationalOptions` labels
  - Configurable join separators for field combinations
  - Enhanced field-settings.md with comprehensive examples
- **NEW**: File streaming API enhancements
  - Password protection support for streamed files
  - Enhanced download and stream endpoints with better error handling
  - Improved file access controls and security

### Enhanced
- **ImageWorks**: Complete text watermarking integration
  - Centralized Roboto font management in `resources/fonts/`
  - Custom font loading from depot with fallback to default font
  - Improved watermark cache management and clearing
  - Better text positioning and angle handling
- **Color System**: Fixed OKLCH color manipulation
  - Proper hue wraparound (360° cycling) for color adjustments
  - Fixed hex color conversion issues with ColorFactory library
  - Enhanced color math operations for design system variables
- **Forms**: Improved select options flexibility
  - Better depot file handling in select dropdowns
  - Enhanced form field rendering with updated icons
- **Documentation**: Comprehensive ImageWorks parameter documentation
  - Complete marktext options reference in twig-totalcms.md
  - Organized parameters into logical sections (Basic, Effects, Watermarks)
  - Practical examples for text watermark usage

### Fixed
- Object cloning now properly resets creation and update timestamps
- Text watermark font loading from depot with proper path structure
- Cache API now correctly clears watermark cache files
- Color hue calculations now properly wrap around 360° boundary
- PHPStan compliance improvements for color data processing
- Form field icon references updated (removed icon-url, added icon-font and icon-angle)
- SelectOptions template calls with proper parameter handling
- CMS depot functionality restored with proper adapter calls

### Changed
- Moved FakerImageGD.ttf to resources/fonts/RobotoRegular.ttf for centralized font management
- Enhanced TextWatermarkFactory with comprehensive font support and error handling
- Improved cache clearing integration across all cache services
- Updated blog schema to include proper created/updated field visibility
- Code style improvements and PHPStan Level 8 compliance throughout

## [3.0.32] - 2025-07-12

### Added
- **NEW**: Complete playground system for testing Twig templates with live data
- **NEW**: `{% cmsgrid %}` Twig tag for flexible content grids with helper methods
- **NEW**: JumpStart system for data import/export with factory generation
- New code field type with CodeMirror integration and syntax highlighting
- Copy-to-clipboard functionality for playground snippets
- `mailto` Twig filter for email links
- `htmlencode` filter with encoding options
- `clearcache` Twig variable for cache management
- Emergency cache clearing capabilities
- Grid renderer with date, tags, excerpt, and price helpers
- Factory system for generating test data with Faker
- Export/import functionality for playground snippets

### Changed
- **BREAKING**: `config` variable in Twig templates changed to `cms.env`
- Reorganized Factory, Twig, and Util classes for better structure
- Enhanced Total CMS 1 import functionality with better error handling
- Improved cache clearing mechanisms and OPcache integration
- Better form handling with disabled autosave on edit forms
- Enhanced dashboard with bundled CSS and improved responsiveness
- Autocapitalize disabled on ID, URL, and Email fields for better mobile UX

### Fixed
- Grid list layouts and template rendering
- Line numbers and code gutters in editors
- Collection factory import issues with images and galleries
- Dashboard JavaScript compatibility issues
- 404 security handling and API URL validation
- Cache issues with collection lists
- Form refresh warnings on playground page
- GitHub test compatibility and stacks preview directory handling

## [3.0.31] - 2025-06-27

### Added
- Form grid layout system with dividers and headers for better organization
- Custom form layout CSS class support (`custom-layout`)
- Natural language default date support (e.g., "today", "tomorrow", "next week")
- New Twig date filters for enhanced date formatting
- Comprehensive test suite for SettingsSaver
- Lazy loading for collection table images
- Password manager interference prevention
- Advanced form grid layouts with dividers and headers
- Enhanced form layout customization options

### Changed
- **CRITICAL**: Settings saver now preserves manual configuration in `tcms.php` when saving through admin
- Major cache management system refactor with new `CacheReporter` class
- Enhanced configuration merging with deep merge support for nested settings
- Smart index rebuilding - only rebuilds when objects are saved/updated
- Improved cache TTL management and reporting
- Enhanced styled text editor with improved toolbar
- Updated logger naming conventions
- Improved new installation detection and setup
- Cache system optimizations
- Better cache TTL management

### Fixed
- Settings being completely overwritten when saving through admin interface
- Empty records being cached unnecessarily
- Styled text styles not saving properly
- Duplicate schema issues in Safari browser
- Server checker version information display
- Batch image URL validation
- Styled text styles not saving
- Settings saver improvements

## [3.0.30] - 2025-06-25

### Added
- **Image Batcher**: New bulk image upload system for galleries
- CodeMirror themes with new syntax highlighting options
- Fire Code font for better code readability
- Updated playground theme

### Changed
- Complete CodeMirror refactor for better performance
- Enhanced styled text toolbar functionality
- Improved cache management error handling
- Automatic cache clear after settings changes
- Refactored IndexFetcher with bug fixes

### Fixed
- Styled text image upload issues
- Playground functionality
- Various code style fixes

## [3.0.29] - 2025-06-25

### Added
- **Security Enhancements**
  - Comprehensive CSRF token management with middleware
  - HTMLPurify integration for XSS attack prevention
  - SVG content sanitization
  - File path protection and upload security validation
  - Content Security Policy (CSP) middleware
  - Enhanced encryption cipher class

- **Import/Export Features**
  - Total CMS v1 import functionality
  - Gallery import with alt text support
  - Export collections to ZIP files
  - Improved CSV import with trimming and logging
  - Import warnings for existing objects

- **UI/UX Improvements**
  - Complete playground redesign with autosave
  - CSS Grid-based form layouts
  - Improved schema editing interface
  - Custom collection labels in dashboard
  - Job queue with retry functionality
  - Cache cleaner UI

- **Twig & Templating**
  - Parsedown for markdown processing
  - New Twig filters: phone, svgSymbol, barcode
  - Configurable markdown links (open in new tabs)

### Changed
- **Performance & Caching**
  - Multi-backend Twig caching system (filesystem, OPcache, Redis, Memcached)
  - Complete cache manager refactor
  - Collection filter/sort performance improvements (30-70% faster)
  - Image cache management with statistics
  - OPcache clearing on errors
  - New caching layer for collections/schemas/objects/indexes

- Session management migrated to Odan\Session\PhpSession
- Dashboard pagination size configuration

### Fixed
- AVIF image generation
- Form saving issues
- Job queue refresh problems
- Duplicate fields in schema forms
- Autogeneration when fields don't exist
- Bad links in pretty URL builder
- ColorThief palette generation errors

## Earlier Versions

For release history before version 3.0.29, please refer to the git history or release tags.

---

[3.0.32]: https://github.com/joeworkman/totalcms/compare/3.0.31...HEAD
[3.0.31]: https://github.com/joeworkman/totalcms/compare/3.0.30...3.0.31
[3.0.30]: https://github.com/joeworkman/totalcms/compare/3.0.29...3.0.30
[3.0.29]: https://github.com/joeworkman/totalcms/compare/3.0.28...3.0.29

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
