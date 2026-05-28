---
title: "Deployment Guide"
description: "Deploy Total CMS to production with Git configuration, cache clearing scripts, CI/CD integration for GitHub Actions and GitLab, and troubleshooting."
related:
  - operations/nginx
  - operations/security
  - operations/updates
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

## Deployment Pipeline

After pulling new code, four things have to happen in the right order to bring a Total CMS site fully up to date:

1. **Composer install** — fetch and optimise PHP dependencies
2. **Frontend build** — compile CSS/JS via Vite or your chosen pipeline
3. **`tcms deploy`** — wipe the compiled DI container, clear application caches, run pending migrations
4. **Reload PHP-FPM** — flush the FPM worker OPcache (CLI can't reach it)

### The `tcms deploy` Command

Total CMS ships a single CLI command that owns the runtime cleanup the library knows how to do safely:

```bash
vendor/bin/tcms deploy
```

It does three things, in order:

| Step | Why |
|------|-----|
| **Wipe `cache/container/`** | The compiled PHP-DI container caches class constructor signatures. When a deploy changes a constructor (new dependency, removed parameter), stale compiled containers crash with `TypeError`. The compiled-class name embeds `container.php`'s mtime but doesn't track other class changes — manual wipe is the only way to force a clean regen. |
| **Clear all application caches** | APCu, Redis, Memcached, filesystem cache, image cache, CLI OPcache. Equivalent to `tcms cache:clear` but bundled into the deploy flow. |
| **Run pending migrations** | One-shot data migrations in `MigrationRunner` get applied immediately rather than firing on the next user request (where a slow migration would manifest as latency). |

Each step has a `--skip-*` flag (`--skip-container`, `--skip-cache`, `--skip-migrations`) for special-case deploys.

`tcms deploy` is the recommended entry point for any deploy script.

### Standard `bin/deploy.sh`

The project skeleton (`totalcms/totalcms-project`) ships a reference script at `bin/deploy.sh`. The shape:

```bash
#!/usr/bin/env bash
set -euo pipefail

PHP_FPM_SERVICE="php8.3-fpm"   # edit for your distro/version

cd "$(dirname "$0")/.."

composer install --no-dev --optimize-autoloader --no-interaction --no-progress

if [ -d frontend ]; then
    ( cd frontend && npm ci --no-audit --no-fund --silent && npm run build )
fi

vendor/bin/tcms deploy

if [ -n "$PHP_FPM_SERVICE" ]; then
    sudo systemctl reload "$PHP_FPM_SERVICE"
fi
```

Wire it up to whatever triggers your deploy — webhook, cron, CI/CD job, etc.

### Why FPM Reload Is Separate

`tcms deploy` runs from CLI, which has its own OPcache instance. PHP-FPM workers each have a **separate** OPcache that the CLI process can't reach. So even after `tcms deploy` resets CLI OPcache, FPM is still serving the old bytecode until you reload it.

`systemctl reload php-fpm` is graceful — in-flight requests finish on the old workers; new requests pick up the new code. No dropped connections, no downtime.

If you run with `opcache.validate_timestamps=1` (slower in steady state but auto-detects file changes), you can skip the reload — but most production setups disable timestamp validation for performance.

### CI/CD Integration

#### GitHub Actions (SSH deploy)

```yaml
- name: Deploy
  uses: appleboy/ssh-action@v1
  with:
    host: ${{ secrets.DEPLOY_HOST }}
    username: deploy
    key: ${{ secrets.DEPLOY_KEY }}
    script: |
      cd /var/www/example.com
      git pull --ff-only
      bash bin/deploy.sh
```

#### GitLab CI

```yaml
deploy:
  script:
    - ssh deploy@$DEPLOY_HOST 'cd /var/www/example.com && git pull --ff-only && bash bin/deploy.sh'
```

### Cache Clear Without Shell Access

For shared-hosting environments where you can't run shell commands, Total CMS exposes an HTTP fallback that clears application caches (but not the compiled DI container or PHP-FPM OPcache):

```bash
curl -s https://example.com/tcms/emergency/cache/clear
```

This is a last resort — it can't wipe `cache/container/` or reload FPM workers, so any deploy that changes class signatures or constructor wiring needs proper shell access to `tcms deploy`.


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

## Symlink Versioned Deployments

For zero-downtime updates with instant rollback, you can use versioned directories with a symlink. This is the same pattern used by Capistrano, Laravel Envoyer, and similar deployment tools.

### Directory Structure

```
/var/www/example.com/
├── tcms -> tcms-3.5.0/          # symlink to active version
├── tcms-3.2.2/                  # previous version (kept for rollback)
├── tcms-3.5.0/                  # current version
├── tcms-data/                   # shared data (never changes between versions)
└── public/
    └── index.php                # references tcms/ (follows symlink)
```

### Deploying a New Version

```bash
# Upload or extract the new version
unzip totalcms-3.5.0.zip -d /var/www/example.com/tcms-3.5.0

# Switch the symlink (atomic operation)
cd /var/www/example.com
ln -sfn tcms-3.5.0 tcms

# Clear cache
php tcms/resources/bin/tcms cache:clear
```

The `ln -sfn` command atomically replaces the symlink. There is no moment where the application is unavailable — requests in progress continue using the old version, and new requests use the new one.

### Rolling Back

```bash
cd /var/www/example.com
ln -sfn tcms-3.2.2 tcms
php tcms/resources/bin/tcms cache:clear
```

### Cleanup

Keep one or two previous versions for rollback, then remove older ones:

```bash
# Remove old versions (keep current and one previous)
rm -rf tcms-3.2.1/
```

### Notes

- `tcms-data/` is shared across all versions — it sits outside the versioned directories and is never touched during deployments
- The `cache/`, `logs/`, and `tmp/` directories inside each version can be symlinked to shared directories if needed, or left as-is (they're recreated automatically)
- This approach works well with CI/CD pipelines — your build step creates the versioned directory, and the deploy step switches the symlink
- The built-in one-click updater in the admin dashboard uses a simpler backup-and-swap approach. The symlink pattern is for teams that manage their own deployment process
