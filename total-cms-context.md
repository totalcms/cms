# Total CMS 3 — Product Knowledge Reference

This document provides comprehensive context about Total CMS 3 for content creation, documentation writing, training material development, and marketing purposes.

## Product Overview

Total CMS 3 is a modern, flat-file Content Management System built with PHP 8.2+. It uses JSON storage instead of a traditional database, making it lightweight, portable, and easy to deploy. Built on the Slim 4 framework with Twig 3 templating, it provides both a full admin interface and a RESTful API.

Total CMS works with **any front-end tooling** — whether that's hand-coded HTML, a visual site builder like Stacks, or a JavaScript framework consuming the REST API. It provides the content management backend while staying agnostic about how the front end is built. For Stacks users specifically, there are dedicated CMS stacks for every content type, plus stacks for looping through collections. Twig syntax can also be used inside native stacks for deeper integration.

### Key Selling Points
- **No database required** — flat-file JSON storage, nothing to configure
- **Simple installation** — drop the `tcms` directory on the server; Stacks users get automatic deployment
- **13 built-in content types** — blog, image, gallery, depot, and more
- **Custom schemas** — design your own content types (Pro edition)
- **Powerful admin interface** — form builder with 30+ field types
- **Twig templating** — 130+ filters, 60+ functions for dynamic content
- **REST API** — headless CMS capability (Pro edition)
- **JumpStart** — export/import system for quick project setup and migration
- **ImageWorks** — on-the-fly image processing with watermarking

---

## Editions

Total CMS is available in three editions. Each higher tier includes everything from the tier below it.

### Lite Edition
**Best for:** Simple sites needing basic content management.

**Content types:** Text, Styled Text, Image, Gallery, Color, Date, Email, Feed, File, Code, Number, SVG, Toggle, URL

**Features:**
- Admin dashboard with content editing
- Dynamic image processing (ImageWorks basics)
- Playground for template testing
- CMS Grid tag for content layouts
- Factory test data generation
- RSS feeds and sitemaps
- CSV and JSON export
- JumpStart export/import
- PHP API for server-side scripting
- Utilities: Cache Manager, Image Batcher, Job Queue Manager, Log Analyzer, Pretty URL Builder

**Form actions:** Redirect, Refresh, Back, Redirect to Object

### Standard Edition
**Best for:** Client sites needing blogs, file management, and email.

**Everything in Lite, plus:**

- **Blog** content type — full blogging with categories, tags, author, featured images
- **Depot** content type — file repository with folders, drag-and-drop, password protection
- **Templates** — reusable content layout templates
- **Mailer** — email notifications from forms
- **Access Groups** — user permission management
- **Image watermarks** — protect client photos with image overlays
- **QR code generation**
- **Basic whitelabel** — brand the admin for clients
- **Additional form fields:** List, Multi-Select, Multi-Checkbox, Depot field

### Pro Edition
**Best for:** Agencies and complex sites needing custom content types and integrations.

**Everything in Standard, plus:**

- **Custom schemas** — design unlimited custom content types
- **Deck field** — repeatable content blocks within objects
- **REST API** — expose content via REST endpoints for headless CMS use
- **API keys** — secure token-based API authentication
- **Webhooks** — trigger external services when content changes
- **Text watermarks** — overlay custom text on images with font control
- **Barcode generation**
- **Full whitelabel** — completely rebrand the admin interface
- **PHP automation** — full TotalCMS.php class for CLI scripting

---

## Content Types (Collections)

Collections are the core data containers. Each collection uses a schema that defines its fields and behavior.

### Built-in Schemas

| Schema | Edition | Description |
|--------|---------|-------------|
| Text | Lite | Plain or formatted text content |
| Styled Text | Lite | Rich text with WYSIWYG editor (TipTap) |
| Image | Lite | Single image with EXIF metadata, alt text, focal point, tags, rating, color palette |
| Gallery | Lite | Image gallery with drag-and-drop ordering |
| File | Lite | Single file upload with metadata and password protection |
| Code | Lite | Code snippets with syntax highlighting |
| Color | Lite | Color values in hex and OKLCH formats |
| Date | Lite | Date/datetime values |
| Email | Lite | Email addresses with validation |
| Feed | Lite | Social media/activity feed posts |
| Number | Lite | Numeric values |
| SVG | Lite | SVG graphics with automatic XSS sanitization |
| Toggle | Lite | Boolean on/off values |
| URL | Lite | Web addresses with validation |
| Blog | Standard | Full blog posts with title, content, categories, tags, author, dates, featured image, gallery |
| Depot | Standard | File repository with hierarchical folders, drag-and-drop, access control |
| Custom | Pro | User-defined schemas with any combination of fields |

### Collection Settings
Each collection has configurable settings including:
- **URL patterns** — pretty URLs or query parameter style
- **Sorting** — sort by any field, manual sort orders
- **Access control** — public operations (read/create/update/delete), group restrictions
- **Categories** — organize collections in the dashboard
- **Schema overrides** — customize field labels, help text, and options per collection
- **Object overrides** — customize field settings per individual object
- **Form behavior** — post-save actions, help text style, error summaries

---

## Form Fields

Total CMS includes 30+ form field types for building content entry forms.

### Text Fields
- **text** — single-line input with pattern validation
- **textarea** — multi-line input with configurable rows
- **email** — email with validation
- **phone** — international phone number
- **password** — with automatic confirmation field
- **url** — web address with validation
- **hidden** — stores data without displaying

### Selection Fields
- **select** — dropdown with relational options support
- **multiselect** — multi-value dropdown (Standard+)
- **radio** — radio button group with grid layout
- **multicheckbox** — checkbox group with grid layout (Standard+)
- **list** — add/remove multiple items (Standard+)

### Date/Time Fields
- **date** — date picker
- **datetime** — date and time picker with auto-population on create/update
- **time** — time-only in 24-hour format

### Media Fields
- **image** — upload with EXIF extraction, focal point, watermarking, validation rules
- **gallery** — multiple images with drag-and-drop ordering
- **file** — file upload with password protection and download tracking
- **depot** — multi-file/folder management (Standard+)

### Rich Content Fields
- **styledtext** — WYSIWYG rich text editor
- **code** — syntax-highlighted editor (HTML, CSS, JS, PHP, Twig, Markdown, etc.)
- **svg** — SVG editor with automatic sanitization

### Specialized Fields
- **color** — color picker with hex and OKLCH
- **number** — numeric with min/max/step
- **price** — currency-formatted number with currency icons
- **range** — slider input
- **toggle** — boolean on/off switch
- **rating** — star rating with count tracking
- **deck** — repeatable content blocks with custom sub-schemas (Pro)
- **json** — raw JSON editor

### Field Features
- **Visibility conditions** — show/hide fields based on other field values
- **Relational options** — pull dropdown options from other collections
- **Validation rules** — file size, image dimensions, aspect ratio, file types
- **ID auto-generation** — templates with variables like `${title}`, `${now}`, `${uuid}`
- **Watermark settings** — per-field image/text watermark configuration

---

## Key Features

### ImageWorks (Image Processing)
On-the-fly image manipulation via URL parameters:
- **Resize and crop** — width, height, fit modes, focal point cropping
- **Format conversion** — JPEG, PNG, WebP, GIF output
- **Effects** — blur, sharpen, pixelate, grayscale, sepia, brightness, contrast
- **Image watermarks** — overlay images with position, size, padding, transparency (Standard+)
- **Text watermarks** — overlay text with custom fonts, size, color, background, angle (Pro)
- **EXIF metadata** — automatic extraction of camera data, GPS, and more
- **Color palette** — automatic color extraction from images

### Depot (File Management)
Full-featured file browser and manager (Standard+):
- Hierarchical folder structure
- Drag-and-drop file uploads
- Keyboard navigation
- File filtering and search
- File preview modals
- Password protection per file
- Access group restrictions
- Auto-saving file metadata
- Folder rename and organization

### Blog System
Complete blogging platform (Standard+):
- Title, content, excerpt, categories, tags
- Author management
- Featured images and galleries
- Publish dates with scheduling
- RSS feed generation
- Category and tag filtering
- Search functionality
- Pagination

### JumpStart (Import/Export)
Project blueprint system for quick setup:
- Export all collections, schemas, settings, and metadata
- Import into new projects instantly
- **Factory system** — generate test data with Faker instead of storing real content
- Factory rules: words, sentences, paragraphs, names, emails, dates, numbers, images, tags
- Media files (images, uploads) are excluded from exports to keep packages lightweight
- Great for project templates, demo data, and client onboarding

### Mailer (Email Notifications)
Form-triggered email system (Standard+):
- SMTP configuration
- Customizable email templates
- Triggered as form actions on content save
- Multiple mailer configurations
- Duplicate existing mailers

### Access Groups
User permission management (Standard+):
- Define user groups with specific permissions
- Control access to collections, schemas, templates, settings, and utilities
- Per-method access control (GET, POST, PUT, DELETE)
- Public vs authenticated access settings
- Collection-level and object-level restrictions

### Whitelabel
Admin interface branding:
- **Basic (Standard):** Login page customization, welcome messages, download auth branding
- **Full (Pro):** Custom logo, admin home replacement, CSS/JS injection, complete interface rebranding
- Template-based with full Twig support
- Dashboard widgets macro for consistent UI

### Cache System
Multi-backend caching for performance:
- **Priority order:** APCu → Redis → Memcached → Filesystem
- OPcache automatic when available
- Admin UI with hit rates and memory usage
- Per-backend clearing
- Emergency clearing endpoint (`/emergency/cache/clear`) for customer self-service

---

## Twig Integration

Total CMS provides extensive Twig templating capabilities accessible via the global `cms` variable and custom filters/functions.

### The `cms` Variable
The primary interface for accessing CMS data in templates:

**Content Access:**
- `cms.text('id')` — plain text
- `cms.styledtext('id')` — rich HTML content
- `cms.image('id', imageworks)` — image tag with optional processing
- `cms.imagePath('id', imageworks)` — image URL only
- `cms.gallery('id', thumbSettings, fullSettings)` — full gallery with lightbox
- `cms.galleryImage('id', 'filename')` — individual gallery image
- `cms.download('id')` — file download link
- `cms.depot('id')` — depot file listing
- `cms.color('id')` — color with hex and OKLCH properties
- `cms.date('id')`, `cms.number('id')`, `cms.toggle('id')`, `cms.url('id')`, `cms.email('id')`, `cms.svg('id')`, `cms.code('id')`

**Collection Queries:**
- `cms.objects('collection')` — all objects from a collection
- `cms.object('collection', 'id')` — single object by ID
- `cms.data('collection', 'id', 'property')` — specific property value
- `cms.objectCount('collection')` — efficient cached count
- `cms.search('collection', 'query', 'property')` — search across properties
- `cms.property('collection', 'property')` — unique values for a property
- `cms.collections()` — all collections
- `cms.collection('name')` — collection metadata

**Configuration:**
- `cms.env` — current environment
- `cms.config('key')` — configuration values
- `cms.domain` — current domain

**Authentication:**
- `cms.userLoggedIn()` — check login status
- `cms.userData()` — current user data
- `cms.userHasAccess()` — permission check
- `cms.login()` — login URL
- `cms.logout` — logout URL

**Utilities:**
- `cms.prettyUrl('/path')` — generate pretty URLs
- `cms.paginationFull(total, page, limit)` — full pagination
- `cms.paginationSimple(total, page, limit)` — prev/next pagination

### Key Twig Filters

**Text:** `markdown()`, `markdownInline()`, `truncate()`, `truncateWords()`, `wordcount()`, `readtime()`, `humanize()`, `titleize()`

**Dates (natural language support):** `dateFormat()`, `dateRelative()` (e.g., "2 days ago"), `dateAdd()`, `dateSubtract()`, `dateDiff()`, `dateIsPast()`, `dateIsFuture()`, `dateIsToday()`

**Collections (performance-optimized):** `keyBy()` (lookup tables), `groupBy()`, `countBy()`, `pluck()`, `sum()`, `avg()`, `min()`, `max()`, `filterCollection()` (40+ operators), `sortCollection()`

**Colors:** `hex()`, `rgb()`, `hsl()`, `oklch()`, `lightness()`, `chroma()`, `hue()`, `adjustColor()`

**Formatting:** `price()` (currency formatting), `filesize()` (human-readable sizes), `mailto()` (obfuscated email links)

**Encoding:** `htmlencode()`, `urlencode()`, `encrypt()`, `decrypt()`

### CMS Grid Tag
Custom Twig tag for rendering collection content in grid layouts:
```twig
{% cmsgrid objects from 'blog' with 'compact gap-md' as 'article' %}
    {{ object.title }}
{% endcmsgrid %}
```

### Global Variables
- `getData` — URL query parameters
- `postData` — POST data
- `sessionData` — session data
- `qr` — QR code generation (Standard+)
- `barcode` — barcode generation (Pro)
- `factory` — Faker test data generation

---

## REST API (Pro Edition)

JSON-based RESTful API for headless CMS use.

### Authentication
- **API Keys** — header (`X-API-Key`) or query parameter (`api_key`)
- **Session auth** — same-origin with CSRF token

### Endpoints
- `GET /api/collections` — list all collections
- `GET /api/collections/{name}` — list objects with filtering and pagination
- `POST /api/collections/{name}` — create object
- `GET /api/collections/{name}/{id}` — get single object
- `PUT /api/collections/{name}/{id}` — replace object
- `PATCH /api/collections/{name}/{id}` — partial update
- `DELETE /api/collections/{name}/{id}` — delete object
- `GET /api/schemas` — list schemas
- `GET /api/schemas/{name}` — schema definition

### File Access
- `/api/download/{collection}/{id}/{property}` — force download
- `/api/stream/{collection}/{id}/{property}` — inline streaming
- `/api/imageworks/{collection}/{id}/{property}.{format}` — processed images

### Features
- Rate limiting with headers
- Pagination headers
- CORS support
- Comprehensive error codes

---

## PHP API

Server-side PHP interface for page rendering and automation.

### Page Rendering
Initialize TotalCMS at the top of any PHP file, write HTML, then call `processBufferMacros()` to render Twig expressions within the page content. This allows mixing standard HTML/PHP with Twig CMS tags.

### Automation (CLI Scripting)
The TotalCMS.php class exposes services for programmatic content management:
- **Reading:** `indexReader()`, `objectFetcher()`, `schemaFetcher()`
- **Writing:** `objectSaver()`, `objectUpdater()`, `objectCloner()`
- **Deleting:** `objectRemover()`
- **Deck items:** `deckItemSaver()`, `deckItemUpdater()`, `deckItemRemover()`, `deckItemFetcher()`
- **Media:** `fileSaver()`, `imageSaver()`
- **Email:** `mailer()`
- **Jobs:** `jobRunner()`
- **Cache:** `clearCache()`, `disableCache()`
- **Logging:** named loggers for custom log channels

---

## Admin Interface

### Dashboard
- Collection overview with object counts
- Quick access to all collections organized by category
- Utilities panel
- Documentation browser with search

### Content Editing
- Auto-generated forms based on schemas
- 30+ field types with validation
- Form error summaries
- Customizable post-save actions (redirect, refresh, etc.)
- Status banners for success/error states

### Utilities
- **Cache Manager** — view hit rates, memory usage, clear by backend
- **Image Batcher** — bulk image processing
- **Job Queue Manager** — background task monitoring
- **Log Analyzer** — view and download error logs
- **Pretty URL Builder** — generate Apache/Nginx rewrite rules
- **Playground** — test Twig templates in a sandbox environment
- **JumpStart** — export/import project data
- **Project Setup** — import from other CMS platforms (Total CMS 1, Alloy CMS)

---

## Security

- **Flat-file storage** — no SQL injection risk
- **HTML sanitization** — automatic XSS prevention on all content
- **SVG sanitization** — removes malicious content from SVG uploads
- **CSRF protection** — token validation on all forms
- **Session management** — secure PHP sessions with regeneration
- **File upload validation** — type, size, extension, MIME checking
- **Password hashing** — secure bcrypt hashing
- **Access groups** — granular permission control
- **Content-Security-Policy** — frame-ancestors support for iframe embedding
- **Emergency cache clearing** — `/emergency/cache/clear` endpoint

---

## Target Audience

Total CMS 3 is used by:
- **Web designers and developers** building client websites with any front-end tooling
- **Stacks users** — the largest existing community, with dedicated CMS stacks for every content type
- **Freelancers and agencies** managing multiple client sites
- **Front-end developers** using JavaScript frameworks who want a headless CMS via the REST API
- **Content creators** who need an intuitive admin interface without database complexity

### Front-End Integration Options
- **Stacks** — dedicated CMS stacks for every content type, collection loop stacks, automatic deployment
- **Hand-coded HTML/PHP** — use Twig syntax or the PHP API directly in any page
- **JavaScript frameworks** — consume the REST API (Pro) for headless CMS architecture
- **Any site builder** — Twig templating works with any tool that outputs HTML on a PHP server

---

## Terminology

- **Collection** — a container of content objects (like a database table)
- **Schema** — defines the fields and structure of a collection (like a table schema)
- **Object** — a single content item within a collection (like a database row)
- **Property** — a field value within an object (like a column value)
- **Depot** — a file repository collection with folder support
- **Deck** — a repeatable set of sub-objects within a single object
- **ImageWorks** — the image processing engine
- **JumpStart** — the data export/import system
- **Factory** — test data generation using Faker
- **Playground** — the admin's Twig template testing sandbox
- **Pretty URLs** — SEO-friendly URL rewrites for collection pages
- **Whitelabel** — admin interface branding customization
