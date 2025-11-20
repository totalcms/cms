# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

## [3.0.46] - 2025-11-19

### Added

- **Emergency License Cache Clear**: New `/emergency/cache/clear-license` endpoint for clearing license cache during debugging
- **Frontend Cache Control**: `noCacheIfAuthenticated()` method in TotalCMS PHP API to disable browser caching for logged-in users on custom pages
- **Admin Keyboard Shortcuts**: Cmd+P (or Ctrl+P) shortcut to preview objects in admin interface
- **Featured-Only Gallery Display**: New `featuredOnly` option for `cms.gallery()`
  - Grid displays only featured images
  - Lightbox shows all images from gallery
  - Clicking featured image opens lightbox at correct position

### Enhanced

- **Gallery Index**: `data-gallery-index` attribute now uses 1-based indexing for better user experience
- **Admin Caching**: No-cache headers automatically added to all admin routes to prevent stale content

### Fixed

- **Featured Toggle**: Featured button icon now updates immediately when clicked without requiring unhover/rehover

## [3.0.45] - 2025-11-18

### Added

- **Gallery Numeric Index**: Access gallery images by numeric index (1-based)
  - `cms.galleryImage(gallery, 1)` returns the first image
  - `cms.galleryImage(gallery, 3)` returns the third image
  - Works with `galleryPath()`, `galleryAlt()`, and `galleryImageData()`
- **Unique Property Support**: Schema properties can now enforce uniqueness across objects
- **SMTP Tester**: New utility to test SMTP email configuration
- **Deck Item Labels**: Custom labels for deck field items with `deckItemLabel` setting
- **Preview Action**: Object preview action in admin interface

### Enhanced

- **Performance Improvements**:
  - Major image processing performance optimizations
  - Request-level memoization for collection and object fetching
  - Reduced response times from ~2000ms to ~340ms in some cases
- **License Caching**: Improved resilience during license server outages
  - Separated cache refresh interval (24h) from storage TTL (7d)
  - License data preserved when clearing all caches
- **Asset Caching**: Better `/assets` endpoint caching
- **Image Caching**: Improved image cache headers with robots indexing support
- **Form System**:
  - Schema field settings now merge with Twig macro settings
  - Better property defaults when not set in request
  - Less strict field change event handling
  - Schema descriptions no longer required
  - Default to `equal` operator for `filterCollection()`
- **Password Reset**: User information included in password reset emails
- **Image Alt Text**: Improved automatic alt text generation
- **Focal Point Cropping**: Better crop focal point for blog post related images
- **Required Validation**: Enhanced validation for image, file, and gallery fields
- **Relational Options**: Can set to `false` to disable; validates array type
- **Data Organization**: Moved `.bundle` and job queue to `tcms-data` directory

### Fixed

- **Setup Flow**: Fixed login redirect to setup on first load
- **Preview Environment**: Skip setup check when in preview environment
- **License Validation**: Better handling when license server is unavailable
- **Deck Fields**:
  - Fixed deck ID setting form conflicts
  - Fixed form ID conflicts with deck items
- **Auth Settings**: Fixed settings being saved as strings instead of proper types
- **Empty Settings**: Fixed saving empty settings values
- **Single Field Forms**: Fixed ID field showing when no object exists
- **Access Controls**: Fixed access controls for non-default auth collections
- **Required Fields**: Fixed empty indexes when new required field is added
- **Checkbox/Toggle**: Fixed not saving when value is false
- **Custom Emails**: Fixed user name display in custom emails
- **Log Content**: Fixed log content ordering
- **Custom Path Setup**: Fixed custom path configuration in setup
- **Preview Admin Embed**: Fixed admin embed in preview mode

### Removed

- **imageFromData**: Removed deprecated `imageFromData` Twig function


## [3.0.44] - 2025-11-11

### Added

- **Blog Post Layout Template**: Complete ready-to-use blog post template (`layouts/blog-post.twig`)
  - Flexible macro-based template with extensive customization options
  - Related posts feature with smart tag/category matching and scoring algorithm
  - Support for compact mode (image + title) or detailed mode (full content)
  - Dynamic filtering using `filterCollection()` for optimal performance
  - Localization options for customizable text strings
  - Hero image with featured badge support
  - Summary, content, gallery, extra content sections
  - Categories and tags with optional links
  - Media embed support
  - Last updated footer with customizable text
- **Feed Layout Template**: Clean template for news feeds and updates (`layouts/feed.twig`)
- **Grid Templates**: New compact blog grid template (`grid/blog-compact.twig`)
- **Gallery Features**: New `galleryDynamic()` and `galleryLauncher()` Twig functions
- **ImageWorks Enhancements**:
  - Multiline text watermark support
  - Smart text mark scaling for better text rendering
  - Barcode generation improvements
  - QR code and embed improvements
- **Collection Management**:
  - Default code collection for storing code snippets
  - New setting to keep ID when duplicating objects
  - Duplicate/clone object action
  - Sort collections by name option
- **Admin Interface**:
  - Admin welcome template for new user onboarding
  - Sentry dashboard integration
  - Gallery view all styles

### Enhanced

- **Cache Performance**: Optimized cache TTL values for better Redis performance
  - Reserved schemas: 1h → 24h (2300% increase)
  - Object data: 1h → 4h
  - Collections list: 15m → 1h
  - Custom schemas: 2h → 4h
  - Improved cache hit rates from ~32% to 60-75%
- **Object Duplication**: Improved duplicate/clone logic across schemas and collections
  - Enhanced `ObjectCloner` with automatic `onCreate`/`onUpdate` date handling
  - Duplicate action renamed to "clone" for clarity
- **Collection Operations**:
  - Collection save efficiency improvements
  - Collections now sorted alphabetically by name
  - Enhanced word boundary checks for better searching
- **User Experience**:
  - Improved new user setup workflow
  - Better droplet error handling and reporting
  - No save warning in playground mode
  - Hide ID field when using `addOnly` with autogen
- **Dark Mode**: Fixed dark mode styling issues
  - Schema icons now properly styled in dark mode
  - Styled text field dark mode support
- **Form System**:
  - Gallery sizing improvements
  - Better error logging for field validation
  - Login form button styling matches other forms
- **Security**:
  - Default to no public access for new collections
  - Better license validation error handling

### Fixed

- **Deck Fields**: Multiple fixes for deck field handling
  - Fixed default values not appearing in deck fields
  - Fixed property settings (min, max, pattern) not making it into deck field settings
  - Fixed empty deck handling and validation
  - Schema now supports empty array or object with proper validation
- **Form Fields**:
  - Fixed default values overruling falsey actual values (0, false, etc.)
  - Fixed boolean default value handling
  - Fixed autogen ID save functionality
  - Fixed depot folder name input validation (now required)
  - Clear value for image and file fields when deleted
- **Admin Interface**:
  - Fixed recent collections display
  - Fixed simple form buttons styling
  - Fixed settings form saving
  - Fixed simple form validation error display
  - Fixed gallery launcher functionality
- **API & Data**:
  - Fixed backwards compatibility with `totalObjects` in Collections
  - Fixed gallery sizing issue
- **Testing**: Multiple test fixes and improvements for CI/CD pipeline

## [3.0.43] - 2025-10-27

### Added

- **Collection Filtering**: Comprehensive new filter system with 14 filter types
  - **Numeric Range**: `between` - Check if number is between min and max (inclusive)
  - **Calendar Periods**: `thisWeek`, `thisMonth`, `thisYear` - Filter by current time periods
  - **Text Length**: `longerThan`, `shorterThan` - Filter by text character count
  - **Array Counting**: `hasMin`, `hasMax`, `hasCount` - Filter by array item counts
  - **Day of Week**: `isWeekday`, `isWeekend`, `dayOfWeek` - Filter by day of week
  - **Relative Dates**: `todayPlusDays`, `todayMinusDays` - Filter by dates relative to today
- **Collection Metadata**: Enhanced collection statistics and tracking
  - `totalObjects` property automatically calculated on collection save
  - `lastUpdated` timestamp for tracking collection modifications
  - Dashboard now displays recent collections based on activity
- **Versioning**: New `cms.version` Twig variable for version information
  - Can be used as asset cache buster for automatic cache invalidation
- **Collection Form Settings**: Enhanced form configuration options for collections
  - Configure help styles of forms
  - Add new/edit/delete actions to forms

### Enhanced

- **Dashboard Improvements**: Better user experience and data visualization
  - Fixed dashboard statistics display with accurate counts
  - Added recent collections section showing recently modified collections
  - Fixed add button functionality
  - Improved cache information display
  - Fixed grid colors for better visual consistency
- **Data Directory Configuration**: Improved default tcms-data directory logic
  - Better automatic detection and configuration
  - Enhanced path resolution for various deployment scenarios
- **Authentication**: More flexible page acess control
  - If no collection is defined for restricting access, then it will only verify the user is valid.

### Fixed

- **Authentication**: Keep me signed in functionality improvements
  - Multiple iterations and fixes for persistent login reliability
  - Better session management and cookie handling
  - Fixed login for custom auth collections
- **UI Components**: Various interface and display fixes
  - Fixed details content overflow issues
  - Fixed details component inside ImageWorks builder
  - Improved buffer controller handling
- **Form & Field Issues**: Better form handling and validation
  - Fixed ID field comma removal for cleaner identifiers
  - Fixed schema import 404 errors
  - 404 error when trying to load an object that does not exist
- **Cache System**: Settings and cache management fixes
  - Fixed cache settings save bug that could cause configuration issues
  - Improved cache information reporting


## [3.0.41] - 2025-10-23

### New

- **Template Management System**: Complete admin interface for managing Twig templates
  - Full CRUD operations (create, read, update, delete) for templates
  - Support for nested template folders with recursive display
  - Template editing with syntax highlighting
  - Moved template API to JSON formatting for consistency
  - ID field now supports `allowCharacter` setting for custom character restrictions
- **Access Control System**: Comprehensive permission management
  - Access groups with granular permissions for collections, schemas, and templates
  - Public/private collection access controls
  - Collection metadata access controls
  - Access control middleware refactoring for better security
  - `accessGroupOptions` field setting for restricting options by access group
  - `protectedByCollection` setting for file and depot fields
  - Admin-only access to access groups and API keys management
- **Settings Architecture**: Settings now save to tcms-data for better portability
  - Settings refactored to store in tcms-data instead of config files
  - Settings form completely redesigned with improved UX
  - Locale setting added for internationalization support
  - Accent color customization in admin interface
  - Fixed Sentry integration enable/disable
- **Schema Inheritance**: Schemas can now inherit properties from parent schemas
  - Inheritance system for schema definitions
  - Improved inherited property handling
  - Collection schemas no longer allow clearValue to prevent accidental deletion
- **API Key Management**: Generate and manage API keys with permissions
  - API key generation and storage in `.system/apikeys.json`
  - API key admin interface with list and creation forms
  - x-api-key header support for API authentication
  - Multicheckbox field for permission selection
  - Copy to clipboard functionality for API keys
  - API key middleware for request validation
- **Password Reset Workflow**: Complete forgot password implementation
  - Forgot password form with email verification
  - Password reset email templates
  - Password reset workflow with secure tokens
  - Processing animations for better UX
- **Mailer Configuration**: SMTP settings and email testing
  - Mailer/SMTP configuration UI in admin
  - Email tester for validating SMTP settings
  - Form mailer action for sending form submissions via email
  - Mailer forms with improved error handling
- **Dark Mode**: Theme switcher for admin interface
  - Complete dark mode theme implementation
  - Dashboard theme switcher
  - Dark mode styles for all admin components
  - Playground dark mode support
  - Image rendering improvements in dark mode
  - List and form styling fixes for dark mode
- **Login Form Macro**: Reusable login form component
  - `cms.form.loginForm()` macro for custom login pages
  - Session-based redirect on login errors
  - Support for custom auth collections
  - Flash message integration
  - Configurable submit label and forgot password link
- **Deck Form**: New deck field type for card-based layouts
  - Initial deck form implementation
  - Deck items automatically sorted after creation
  - Deck form documentation

### Enhcancements

- **Admin Interface Improvements**: Better UX and mobile support
  - Mobile-responsive admin interface with improved navigation
  - Homepage dashboard with quick actions and collection overview
  - Collapsible sidebar groups (default to open)
  - Better form error display and handling
  - Dialog and detail style improvements
  - Gallery drag-and-drop improvements
  - New sortable class for improved drag behavior
- **Whitelabel Support**: Customize Total CMS branding
  - Support for custom admin pages
  - Custom admin logo upload
  - Whitelabel templates for login and error pages
  - Custom templates in `whitelabel/` directory
- **JumpStart Enhancements**: Improved data import/export
  - Streaming export for memory efficiency with large datasets
  - Templates included in JumpStart data
- **Image Support**: HEIC image upload and processing
  - HEIC format support for modern Apple devices
  - Automatic conversion and processing
- **Twig Filters & Functions**: Enhanced template capabilities
  - `markdownInline` filter for inline markdown rendering
  - `download` and `stream` macro fixes for custom collections
- **Property Increment/Decrement API**: Utility endpoints for numeric properties
  - POST `/collections/{collection}/{id}/{property}/increment[/{amount}]`
  - POST `/collections/{collection}/{id}/{property}/decrement[/{amount}]`
  - Respects min/max schema settings
  - Default increment/decrement amount is 1
- **Data Types**: New field types for advanced data structures
  - Code field type with syntax highlighting
  - Array field type for structured data
- **IndexFilter Service**: Advanced filtering for collections
  - Include/exclude options for index fetching
  - Array support for filtering
  - Filters for relational options
  - IndexFilter limits for RSS feeds

### Fixed

- **Forms & Validation**: Improved form handling
  - Simple form submit issues resolved
  - Form error display improvements
  - Form action array support for multiple actions
  - Delete form error handling
  - Save action fixes
  - SVG field saves properly when in code view
  - Profile image removal when not set up
- **Admin Interface**: UI and navigation fixes
  - Dashboard links now relative to /admin
  - Admin utils pages accessibility fixed
  - Fixed /admin 404 routes
  - CodeMirror bracket matching color in dark mode
  - Code view sizing improvements
  - Code autoclose fixes
  - HTML syntax highlighting improvements
  - Twig syntax highlighting inherits from HTML
- **Security & Authentication**: Enhanced security
  - CSRF token fixes in preview mode
  - Middleware organization improved
  - isAdmin fix for auth disabled mode
  - Better API route checking
- **Data Handling**: Object and property fixes
  - Template schema fixes
  - Duplicate schema handling
  - Settings schema fetcher fixes
  - Installation settings form fixes
- **Performance & Optimization**: Better resource handling
  - Emergency cache clear debug output improvements
  - UI icon cleanup and optimization
  - Better accordion animations
  - htaccess improvements to prevent redirect loops
  - Auto-creation of .htaccess in tcms-data for security
  - Increased download max attempts setting
- **Build & Development**: Developer experience improvements
  - Sample nginx configuration included
  - Parsedown dependency patch
  - Various test suite improvements

### Changed

- **API Settings**: API URL now dynamically set
  - API setting no longer in settings form (automatically configured)
  - Removed non-GET requests from collection meta API
- **Sitemap Builder**: Filter option renamed
  - Changed from filter to include for clarity
- **Download Attempts**: Increased default max download attempts
- **Image Settings**: Turned off image max height restriction

## [3.0.40] - 2025-09-30

### Enhanced

- **License System**: Streamlined license validation and display
  - Simplified LicenseData structure reduced from 15+ to 8 essential fields
  - Consistent camelCase throughout API responses and JWT tokens
  - JWT validation moved to dedicated LicenseValidator service
  - License status icon in sidebar with progressive trial urgency indicators
  - Domain-specific license caching for multi-site deployments
  - CLI and auth routes bypass license validation for better developer experience
- **Form Fields**: Enhanced select and list field functionality
  - Select fields now include clear button (×) that appears when value is selected
  - Clear button can be disabled with `clearValue: false` setting
  - Radio fields support `sortOptions` for alphabetical sorting
  - Fixed list field asString + required validation
  - Fixed list field data ordering with relational options
  - Schema select fields properly disable clear button to prevent accidental deletion
- **Session & Cache Management**: Improved isolation and security
  - Fixed session and cache leakage between domains
  - Fixed cookie leak between domains
  - Better session save path handling for cPanel servers
  - Cache license data stored outside devmode restrictions
  - Deep merge support for configuration arrays (with revert and refinement)
- **Logging & Debugging**: Replaced error_log with structured logging
  - All error_log calls replaced with PSR LoggerInterface
  - IndexBuilder now logs failed object loads instead of failing silently
  - CacheManager, TextWatermarkFactory, ImageGenerator use LoggerFactory
  - DeckCompatibilityChecker optional LoggerFactory integration
- **Admin Interface**: UI and UX improvements
  - New Total CMS logo in dashboard
  - License status icon size adjustments
  - Object count moved to collection header with better positioning
  - Performance warning for queue processing on save
  - Dashboard button no-wrap improvements
  - Server checker includes license information
  - Cache manager page performance optimizations

### Added

- **Sitemap Builder**: Filter and exclude capabilities
  - New documentation for sitemap filtering (`sitemap-filtering.md`)
  - Enhanced sitemap generation with filter options
- **Factory & Testing**: Job queue integration
  - Factory data generation uses job queue for better performance
  - Factory form improvements with better queue integration
- **Autogen Enhancements**: Special character handling
  - Improved autogen to handle special characters properly
  - Fixed autogen only replacing first dot occurrence

### Fixed

- **Authentication**: Login and session improvements
  - Keep me signed in refactor for better reliability
  - User download logging
  - Fixed session tmp dir issues
  - Better session path handling for problematic servers
- **Data Integrity**: Object and property handling
  - Fixed getvalue for list to preserve item order
  - Fixed color import issues
  - Duplicate objects now properly increment counters
  - Fixed list data ordering with relational options
- **Configuration**: Bundle and settings improvements
  - Added config validation to bundle check
  - Fixed setting hijack in test environment
  - Improved embedded store handling
- **Testing**: Test suite fixes
  - Multiple test fixes for improved reliability
  - License validation test coverage
  - Session and authentication test improvements

### Changed

- **Configuration System**: Deep merge arrays support (experimental, reverted, then refined)
  - Attempted deep merge for user configuration overrides
  - Reverted due to complexity concerns
  - Settings system remains with traditional override pattern

## [3.0.39] - 2025-08-28

### Enhanced

- **Admin Interface Performance**: Major AdminTable optimizations for large datasets
  - Event delegation reduces memory usage from hundreds to just 2 event listeners per table
  - Added grid initialization guards to prevent multiple executions
  - Dynamic throttling based on dataset size (rowCount/4, max 2000ms, no throttle <400 rows)
  - Event-driven pagination fixes using GridJS state transitions
- **Schema Property Management**: Improved sortable behavior in schema forms
  - Fixed drag-and-drop interference with text selection in Firefox, Chrome, and Safari
  - Long-press detection prevents accidental dialog opening after drag operations
  - Cross-browser compatibility with `forceFallback: true` for consistent drag behavior
- **Cache Management**: Renamed and improved cache interface
  - "Cache Cleaner" renamed to "Cache Manager" throughout admin interface
  - Updated navigation, templates, and documentation references
  - Better reflects comprehensive cache management capabilities

### Fixed
- **Browser Compatibility**: Fixed text selection issues in dialogs across all major browsers
  - Resolved SortableJS interference with form inputs in schema property dialogs
  - Implemented browser-specific workarounds for consistent drag-and-drop behavior
  - Long-press detection prevents unintended dialog triggers after dragging
- **AdminTable Performance**: Eliminated performance bottlenecks in large data grids
  - Fixed multiple grid initialization causing hundreds of redundant event listeners
  - Resolved pagination breaking issue with large datasets through event-based re-rendering
  - GridJS state management improvements for reliable initialization timing
- **Authentication & Session Management**: Enhanced login system reliability
  - Improved session handling and access control
  - Better redirect parameter support for login flows
  - Enhanced super admin access capabilities across auth collections
  - Fixed status banner animation issues

### Added
- **Form Enhancement**: New "addonly" form mode for restricted editing scenarios
- **ImageWorks**: Fixed border handling issues in image processing
- **Testing**: Expanded test coverage for login, session, and authentication workflows

## [3.0.38] - 2025-08-26

### Added
- **NEW**: Radio field type with enhanced grid display support
  - Comprehensive radio field implementation with JavaScript integration
  - Grid-specific radio field rendering and styling
  - Complete documentation for radio field configuration
- **NEW**: Price field type for e-commerce and pricing data
  - Dedicated price field with currency support
  - New currency icons and formatting options
  - Enhanced documentation for price field usage
- **NEW**: Auto-generated ID service for objects
  - `autogen` setting for automatic ID generation on object creation
  - Object creation counters for collections with unique ID generation
  - Better handling of ID fields in deck systems

### Enhanced
- **Testing & Code Quality**: Comprehensive test suite improvements
  - Extensive test coverage for authentication, properties, ImageWorks, and Twig systems
  - PHPStan Level 8 compliance improvements throughout codebase
  - Rector-based code modernization and cleanup
  - Enhanced CI/CD pipeline with improved test reliability
- **Form System**: Major improvements to form handling and validation
  - Fixed schema default values not populating in new object forms
  - Enhanced multi-file upload reliability with improved state management
  - Better form state handling for file upload processes
  - Improved droplet count logic and queue processing
- **Cache System**: APCu integration as primary cache backend
  - APCu cache service with zero-configuration setup
  - Optimized cache priority for single-server deployments
  - Enhanced cache management with detailed statistics
  - Better error handling and cache clearing mechanisms
- **Image Processing**: Enhanced EXIF metadata extraction
  - Native PHP EXIF implementation for PHP 8.4 compatibility
  - Improved camera info and location data extraction
  - Better image metadata processing with automatic alt text population

### Fixed
- **Browser Compatibility**: Safari dialog text selection issues
  - Fixed SortableJS interference with text selection in dialogs
  - Added proper drag handles to prevent unwanted drag behavior
  - Improved dialog interaction and form field accessibility
- **File Uploads**: Multi-file upload reliability improvements
  - Fixed gallery uploads stopping after first file
  - Enhanced Dropzone event handling from "success" to "queuecomplete"
  - Better parallel upload handling with data integrity protection
- **CI/CD**: GitHub Actions test environment fixes
  - Resolved session permission errors in CI environment
  - Fixed readonly class property initialization issues
  - Improved test environment compatibility
- **Code Quality**: PHPCBF and PHPCS configuration alignment
  - Separate PHPCBF configuration to prevent spacing conflicts
  - Better code formatting consistency across development environments
  - Enhanced development workflow with proper linting rules

### Changed
- **Color System**: Migration to enhanced Couleur library fork
  - Custom fork with improved OKLCH hue wraparound calculations
  - Better color manipulation and hex conversion reliability
  - Enhanced color data processing with proper mathematical operations
- **Development Workflow**: Improved build and publishing processes
  - Reduced publishing footprint for better deployment efficiency
  - Enhanced bundle creation and asset management
  - Better development mode handling and cache management

### Developer Notes
- Enhanced test coverage across core systems with focus on reliability
- Rector-based code modernization improving PHP 8+ compatibility
- Comprehensive CI/CD improvements for better development workflow
- Enhanced debugging and error handling throughout the system

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
