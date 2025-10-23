# Total CMS Changelog

All notable changes to Total CMS will be documented in this file.

## [3.0.41] - 2025-10-23

### Stacks 

- LoginForm stack
- AccessChecker stack
- Conditional improvements
- Form multi-actions
- Admin Core theme
- Image Stack sizing
- Template stack

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
