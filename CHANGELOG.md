# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

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
