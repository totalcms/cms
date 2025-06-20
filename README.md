# Total CMS

<p align="center">
  <strong>A modern, PHP-based flat-file Content Management System</strong>
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#documentation">Documentation</a> •
  <a href="#contributing">Contributing</a> •
  <a href="#license">License</a>
</p>

---

## Overview

Total CMS is a powerful, API-first content management system that uses flat-file JSON storage instead of traditional databases. Built with modern PHP and the Slim 4 framework, it provides a comprehensive solution for managing dynamic content with an intuitive admin interface and extensive developer features.

### Key Highlights

- **No Database Required**: Uses efficient flat-file JSON storage
- **RESTful API**: Complete API with OpenAPI/Swagger documentation
- **13 Built-in Collections**: Blog, Gallery, Images, Files, and more
- **Custom Collections**: Define your own business objects with JSON schemas
- **Modern PHP**: Built with PHP 8.2+, follows PSR standards, PHPStan Level 8
- **Twig Templates**: Powerful templating with custom filters and functions
- **Admin Interface**: Full-featured admin panel with form builder and data tables
- **Developer Friendly**: Comprehensive API, extensive documentation, and testing suite

## Features

### Content Management
- **Built-in Collections**: Blog, Image, Gallery, File, Depot, Toggle, Text, Video, Audio, Feed, CSV, Social, and more
- **Custom Collections**: Create your own content types with JSON Schema validation
- **Rich Media Support**: Image processing, galleries, file management with drag-and-drop
- **Content Relationships**: Link content across collections
- **Import/Export**: CSV and JSON import/export capabilities
- **Job Queue**: Background processing for large operations

### Developer Features
- **RESTful API**: Full CRUD operations with authentication
- **Twig Integration**: 40+ custom filters and functions
- **Middleware System**: Authentication, CORS, license validation, request transformation
- **Extensible Architecture**: Domain-driven design with clear separation of concerns
- **Modern Tooling**: ESBuild, Sass/SCSS, TypeScript support
- **Testing Suite**: Comprehensive tests with Pest PHP testing framework

### Admin Interface
- **Form Builder**: 20+ field types with validation
- **Data Tables**: Sortable, filterable data grids
- **Media Manager**: Upload and manage images, files, and galleries
- **User Management**: Role-based access control
- **License Management**: Built-in license validation system
- **Activity Logging**: Track all system changes

### Performance & Security
- **Caching**: Twig template caching, image caching
- **Image Processing**: On-the-fly image resizing and optimization
- **CSRF Protection**: Built-in CSRF token management
- **XSS Prevention**: HTML and SVG sanitization
- **Session Management**: Secure session handling
- **Rate Limiting**: API rate limiting support

## Requirements

- PHP 8.2 or higher
- Composer 2.0+
- Node.js 18+ and Yarn (for building assets)
- Web server (Apache/Nginx) with URL rewriting
- PHP Extensions:
  - PDO SQLite (for job queue)
  - GD or ImageMagick (for image processing)
  - JSON
  - Fileinfo
  - OpenSSL

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/yourusername/totalcms.git
cd totalcms
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install JavaScript dependencies
yarn install
```

### 3. Build Assets

```bash
# Build frontend assets
composer run build
# or
bin/build.sh
```

### 4. Configure Web Server

#### Apache (.htaccess included)
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

#### Nginx
```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

### 5. Set Permissions

```bash
# Ensure write permissions for data directories
chmod -R 755 tcms-data/
chmod -R 755 cache/
chmod -R 755 logs/
```

### 6. Initial Setup

1. Navigate to `/admin` in your browser
2. Create your first admin user
3. Configure your license (if applicable)
4. Start creating content!

## Quick Start

### Creating Content via Admin

1. Log into the admin panel at `/admin`
2. Navigate to your desired collection (e.g., Blog)
3. Click "Add New" to create content
4. Fill in the form fields and save

### Using the API

```php
// Initialize Total CMS
$tcms = new TotalCMS();

// Fetch all blog posts
$posts = $tcms->api('blog');

// Get a specific post
$post = $tcms->api('blog', 'my-post-slug');

// Create new content
$tcms->save('blog', [
    'title' => 'My New Post',
    'content' => 'Post content here...',
    'date' => date('Y-m-d')
]);
```

### Twig Templates

```twig
{# List all blog posts #}
{% set posts = api('blog') %}
{% for post in posts %}
    <article>
        <h2>{{ post.title }}</h2>
        <p>{{ post.content|markdown }}</p>
        <time>{{ post.date|date('F j, Y') }}</time>
    </article>
{% endfor %}

{# Get specific content #}
{% set hero = api('image', 'hero-image') %}
<img src="{{ hero.image }}" alt="{{ hero.alttext }}">
```

## Documentation

### Core Documentation
- [API Reference](resources/docs/api.md) - Complete API documentation
- [Configuration Guide](resources/docs/configuration.md) - System configuration options
- [Data Model](resources/docs/data-model.md) - Understanding the data structure
- [Twig Templates](resources/docs/twig-overview.md) - Template system overview

### Developer Guides
- [Architecture Overview](ARCHITECTURE.md) - System design and patterns
- [Contributing Guide](CONTRIBUTING.md) - How to contribute to the project
- [Testing Guide](TESTING.md) - Running and writing tests
- [Extension Development](resources/docs/extensions.md) - Creating custom functionality

### Collection Types
- [Blog](resources/docs/collections/blog.md) - Blog post management
- [Gallery](resources/docs/collections/gallery.md) - Image galleries
- [File Management](resources/docs/collections/files.md) - File and depot collections
- [Custom Collections](resources/docs/collections/custom.md) - Creating your own types

## Development

### Commands

```bash
# Development server with auto-reload
bin/watch.sh

# Run tests
composer run test

# Code quality checks
composer run test:all  # Runs all checks
composer run stan      # PHPStan analysis
composer run cs:fix    # Fix code style

# Build for production
composer run bundle
```

### Project Structure

```
totalcms/
├── src/                  # PHP source code
│   ├── Action/          # HTTP controllers
│   ├── Domain/          # Business logic
│   ├── Middleware/      # HTTP middleware
│   └── Utils/           # Utility classes
├── config/              # Configuration files
├── resources/           # Templates, schemas, docs
├── javascript/          # Frontend JavaScript
├── tcms-data/          # Content storage (JSON files)
├── tests/              # Test suite
└── public/             # Web root
```

## Contributing

We welcome contributions! Please see our [Contributing Guide](CONTRIBUTING.md) for details on:

- Development setup
- Coding standards
- Testing requirements
- Pull request process

## Support

- **Documentation**: Check the [docs](resources/docs/) directory
- **Issues**: Report bugs on [GitHub Issues](https://github.com/yourusername/totalcms/issues)
- **Discussions**: Join our [community forum](https://forum.totalcms.co)
- **Commercial Support**: Available at [totalcms.co](https://totalcms.co)

## License

Total CMS is proprietary software. See [LICENSE](LICENSE) for details.

---

<p align="center">
  Built with ❤️ by the Total CMS team
</p>