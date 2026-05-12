---
title: "Installation"
description: "Install Total CMS on any PHP 8.2+ server with the step-by-step setup wizard. Covers requirements, data path configuration, and first admin account creation."
---

# Installation

Total CMS can be installed on any server running PHP 8.2 or higher. The setup wizard guides you through the process in under 5 minutes.

## System Requirements

### PHP Requirements

- **PHP 8.2 or higher** (PHP 8.3 and 8.4 supported)
- Required PHP extensions:
  - `curl` - HTTP requests (license validation, embeds)
  - `exif` - Image metadata extraction
  - `fileinfo` - File type detection
  - `gd` (with FreeType) - Image processing and text rendering
  - `json` - JSON parsing
  - `mbstring` - Multibyte string handling
  - `openssl` - HTTPS and encryption

### Recommended PHP Extensions

These extensions enhance performance and enable additional features:

- `intl` - Internationalization and locale support
- `imagick` - Advanced image processing
- `opcache` - PHP bytecode caching (strongly recommended)
- `apcu` - High-performance in-memory caching
- `redis` - Redis caching support
- `memcached` - Memcached caching support

You only need one caching extension. APCu is recommended for single-server deployments.

### Web Server

- **Apache 2.4+** with `mod_rewrite` enabled
- **Nginx** with PHP-FPM (see [Nginx Configuration](/advanced/nginx/))
- **LiteSpeed** or other PHP-compatible servers

### File System

- Write access to the data directory
- Recommended: 100MB+ free disk space (varies by content volume)

### Browser Support (Admin Dashboard)

- Chrome/Edge (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)

## Quick Start

Install with Composer:

```bash
composer create-project totalcms/totalcms my-site --stability=beta
```

> **Note:** `--stability=beta` is required while 3.5.0 is in beta. Once 3.5.0 stable ships, you can drop the flag.

Then:

1. Point your web server's document root to `my-site/public/`
2. Visit your site in a browser — the setup wizard starts automatically

### Alternative: zip download

If you can't use Composer on the target server (shared hosting, restricted environments), download the Total CMS zip from [totalcms.co](https://totalcms.co), extract it to your server, and point your document root at the extracted `public/` directory. The setup wizard works the same way.

## Setup Wizard

The wizard walks you through the installation:

### Welcome

Choose your preferred language for the admin interface. Currently supported: English, English (UK), Deutsch, Español, Nederlands.

### Environment Check

The wizard verifies your server meets the requirements. All required checks must pass before you can continue. Optional checks are shown as recommendations.

### Data Path

Choose where Total CMS stores its data:

- **Document Root** — `<docroot>/tcms-data` (simplest, works everywhere)
- **Above Document Root** (recommended) — `<parent>/tcms-data` (more secure, not web-accessible)
- **Custom Path** — any absolute path on the server

The data directory is created with an `.htaccess` file that blocks direct web access.

### Admin Account

Create your first administrator account with an email address and password.

### License

Your license is automatically validated. New installations start with a free trial. If you've already purchased a license for your domain, it will be detected automatically.

## Directory Structure

After installation:

```
/var/www/example.com/
├── tcms/                    # Total CMS application
│   ├── config/              # Configuration files
│   ├── public/              # Web root (point your server here)
│   │   ├── index.php        # Entry point
│   │   └── assets/          # CSS, JS, images
│   ├── resources/           # Templates, schemas, translations, docs
│   ├── src/                 # PHP source code
│   ├── vendor/              # Dependencies
│   └── version.json         # Version info
├── tcms-data/               # Your content (separate from app)
│   ├── .schemas/            # Custom schema definitions
│   ├── .system/             # System files (settings, API keys)
│   ├── templates/           # Custom Twig templates
│   └── [collections]/       # Collection data
```

The key principle: `tcms/` contains the application and `tcms-data/` contains your content. Updates only touch `tcms/` — your content is never affected.

## Web Server Configuration

### Apache

Total CMS includes `.htaccess` files for URL rewriting. Ensure `mod_rewrite` is enabled:

```bash
a2enmod rewrite
```

Your virtual host should point to the `public/` directory:

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/example.com/tcms/public

    <Directory /var/www/example.com/tcms/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx

See the [Nginx Configuration](/advanced/nginx/) guide for detailed setup.

## CLI

After installation, Total CMS includes a CLI tool:

```bash
php tcms/resources/bin/tcms info
```

See the [CLI Commands](/advanced/cli/) reference for the full list.

## Troubleshooting

**Setup wizard doesn't appear** — Ensure your web server points to `tcms/public/`, not the `tcms/` folder itself.

**Permission denied errors** — The web server user (e.g., `www-data`) needs write access to `tcms-data/`, `tcms/cache/`, `tcms/logs/`, and `tcms/tmp/`.

**Required extension missing** — Install the missing PHP extension and restart your web server. On Ubuntu: `apt install php8.2-{extension}`.

**Blank page or 500 error** — Check `tcms/logs/` for error logs. Verify PHP version is 8.2+ and all required extensions are installed.

**404 errors on all pages** — Verify `mod_rewrite` is enabled (Apache) or check your Nginx `try_files` directive.

**License validation fails** — Verify `curl` is installed, firewall allows outbound HTTPS, and domain matches your license.

### Getting Help

1. Check the [Community Forum](https://community.weavers.space/total-cms)
2. Review the [Configuration](/advanced/configuration/) guide
3. Check PHP error logs for specific error messages
