---
title: "System Requirements"
description: "Server, PHP, and browser requirements for running Total CMS. Required and recommended PHP extensions, supported web servers, and admin dashboard browser support."
related:
  - get-started/installation
  - operations/nginx
---

# System Requirements

Total CMS runs on any server with **PHP 8.2 or higher**. The setup wizard checks everything for you on first load — so you only really need this page if you want to verify your server *before* you install.

## Quick check

Run these in your terminal to see your PHP version and confirm every required extension is loaded:

```bash
php -v
php -r 'foreach (explode(" ", "curl exif fileinfo gd json mbstring openssl") as $e) echo (extension_loaded($e) ? "✓" : "✗") . " $e\n";'
```

A `✗` next to anything means you'll need to install that extension before continuing. The rest of this page explains what each one is for.

## PHP

Total CMS supports **PHP 8.2+**. Earlier versions are not supported.

### Required extensions

These must all be present. The setup wizard refuses to continue if any are missing.

| Extension | What it's for |
|---|---|
| `curl` | HTTPS requests — license validation, embeds, outgoing mail |
| `exif` | Image metadata extraction |
| `fileinfo` | File type detection on uploads |
| `gd` (with FreeType) | Image processing and text rendering |
| `json` | JSON parsing — used everywhere, since storage is flat-file JSON |
| `mbstring` | Multibyte string handling |
| `openssl` | HTTPS, encryption, and password hashing |

### Recommended extensions

Not required, but they unlock features or improve performance.

| Extension | What you get |
|---|---|
| `opcache` | PHP bytecode caching — **strongly recommended for production** |
| `intl` | Internationalization and locale-aware formatting |
| `imagick` | Higher-quality image processing |
| `apcu` | Fastest in-memory cache, ideal for single-server deployments |
| `redis` | Redis cache backend, for multi-server deployments |
| `memcached` | Memcached cache backend, also for multi-server deployments |

You do need every cache extension. T3 picks the fastest available automatically: APCu → Redis → Memcached → filesystem.

T3 does support a 3 stage cacheing system. In order to achieve this, we recommend that you use Opcache, APCu
and Redis or Memcached. This setup will provide you the best setup for caching.

## Web server

Any PHP-capable server with URL rewriting will work.

| Server | Notes |
|---|---|
| Apache | `mod_rewrite` must be enabled. T3 ships an `.htaccess` |
| Nginx | Use PHP-FPM. See [Nginx Configuration](operations/nginx) |
| Caddy | Use PHP-FPM, same fundamentals as Nginx — different config syntax |
| LiteSpeed | Works out of the box |
| FrankenPHP | Classic mode works like PHP-FPM. **Worker mode is unverified** — use at your own risk for now |

## File system

- Write access to the data directory you choose during setup
- ~100 MB free disk space for the application itself
- Additional space scales with your content (uploads, image variants)

## Browser support (admin)

The admin dashboard targets evergreen browsers:

| Browser | Supported versions |
|---|---|
| Chrome / Edge | Latest 2 |
| Firefox | Latest 2 |
| Safari | Latest 2 |

These requirements only apply to the admin. Your public-facing site is rendered by your own templates, so browser support there is entirely up to you.

## What the wizard checks

When you load your install URL for the first time, the [setup wizard](get-started/installation) runs an environment check before anything else. Required extensions must all pass; recommended ones show up as friendly suggestions. If something's missing, you'll see exactly what's wrong and what to install — no guessing.
