---
title: "System Requirements"
description: "Server, PHP, and browser requirements for running Total CMS. Covers required and recommended PHP extensions, supported web servers, and admin dashboard browser support."
---

# System Requirements

Total CMS runs on any server with PHP 8.2 or higher. This page lists everything your server needs before you [install](/installation/).

## PHP

- **PHP 8.2 or higher** (PHP 8.3 and 8.4 supported)

### Required Extensions

- `curl` — HTTP requests (license validation, embeds)
- `exif` — Image metadata extraction
- `fileinfo` — File type detection
- `gd` (with FreeType) — Image processing and text rendering
- `json` — JSON parsing
- `mbstring` — Multibyte string handling
- `openssl` — HTTPS and encryption

### Recommended Extensions

These extensions enhance performance and enable additional features:

- `intl` — Internationalization and locale support
- `imagick` — Advanced image processing
- `opcache` — PHP bytecode caching (strongly recommended)
- `apcu` — High-performance in-memory caching
- `redis` — Redis caching support
- `memcached` — Memcached caching support

You only need one caching extension. APCu is recommended for single-server deployments.

## Web Server

- **Apache 2.4+** with `mod_rewrite` enabled
- **Nginx** with PHP-FPM (see [Nginx Configuration](/advanced/nginx/))
- **LiteSpeed** or other PHP-compatible servers

## File System

- Write access to the data directory
- Recommended: 100MB+ free disk space (varies by content volume)

## Browser Support (Admin Dashboard)

- Chrome/Edge (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)

## Verifying Your Server

The setup wizard runs an environment check on first load and confirms that every required extension is present. All required checks must pass before you can continue; optional checks are shown as recommendations.
