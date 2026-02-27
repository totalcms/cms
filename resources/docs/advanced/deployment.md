---
title: "Deployment Guide"
description: "Deploy Total CMS to production with Git configuration, cache clearing scripts, CI/CD integration for GitHub Actions and GitLab, and troubleshooting."
---

# Deployment Guide

This guide covers best practices for deploying Total CMS sites to production and managing deployments with version control.

## Git Configuration

When using Git for version control, add the following to your `.gitignore` file to exclude Total CMS runtime files:

```gitignore
# Total CMS 3
tcms-data
**/tcms/cache
**/tcms/logs
**/tcms/tmp
```

### What These Directories Contain

| Directory | Purpose | Why Ignore |
|-----------|---------|------------|
| `tcms-data` | All CMS content (collections, files, uploads) | Content is environment-specific and managed through the CMS |
| `tcms/cache` | Twig template cache, computed data | Generated at runtime, varies by environment |
| `tcms/logs` | Application logs | Environment-specific, should not be shared |
| `tcms/tmp` | Temporary files (uploads in progress, etc.) | Transient data |

### Files to Commit

You should commit your customization files:

- `tcms.php` - Site configuration
- Custom templates in your theme directory
- Custom schemas if you've created any

## Clearing Cache on Deployment

After deploying new code or templates, clear the cache to ensure visitors see the latest content. Total CMS provides an emergency cache clear endpoint that can be called from deployment scripts.

### Using curl

The simplest approach is to call the cache clear endpoint directly:

```bash
curl -s https://example.com/tcms/emergency/cache/clear
```

For formatted output:

```bash
curl -s https://example.com/tcms/emergency/cache/clear | jq
```

### Example Deployment Script

```bash
#!/bin/bash

SITE_URL="https://example.com"

# Pull latest code
git pull origin main

# Clear cache
echo "Clearing cache..."
RESPONSE=$(curl -s "$SITE_URL/tcms/emergency/cache/clear")

# Check if successful
if echo "$RESPONSE" | grep -q '"success":true'; then
    echo "Cache cleared successfully"
else
    echo "Warning: Cache clear may have had issues"
    echo "$RESPONSE"
fi

echo "Deployment complete"
```

### CI/CD Integration

#### GitHub Actions

```yaml
- name: Clear cache
  run: |
    curl -s -f https://example.com/tcms/emergency/cache/clear || echo "Cache clear failed"
```

#### GitLab CI

```yaml
deploy:
  script:
    - curl -s -f https://example.com/tcms/emergency/cache/clear || echo "Cache clear failed"
```

### What Gets Cleared

The cache clear endpoint clears:

- **Filesystem cache** - Cached templates and computed data
- **OPcache** - PHP bytecode cache
- **APCu** - In-memory application cache (if enabled)
- **Redis** - Redis cache (if configured)
- **Memcached** - Memcached cache (if configured)
- **Image cache** - Processed/watermarked images
- **Cache version** - Forces cache invalidation across all backends


## Troubleshooting Deployments

### Changes Not Appearing

1. Clear the cache using the endpoint above
2. Check that OPcache is clearing (may require PHP-FPM restart)
3. Verify CDN cache is cleared if using one

### Cache Clear Endpoint Not Working

If the endpoint returns an error:

1. Check that the site is accessible
2. Review PHP error logs
3. As a fallback, restart PHP-FPM: `systemctl restart php-fpm`

### Permission Issues After Deployment

Ensure the web server user has write access to:

- `tcms-data/` - Content storage
- `tcms/cache/` - Template cache
- `tcms/logs/` - Application logs
- `tcms/tmp/` - Temporary files

```bash
chown -R www-data:www-data tcms-data tcms/cache tcms/logs tcms/tmp
```
