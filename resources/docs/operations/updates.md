---
title: "Updates"
description: "Keep Total CMS up to date with one-click dashboard updates, CLI commands, or manual upgrades. Includes rollback and maintenance mode details."
since: "3.5.0"
---

# Updates

Total CMS includes a built-in update system that checks for new versions and applies them with minimal downtime.

## How Updates Work

1. Total CMS periodically checks the license server for new versions (cached for 24 hours)
2. When an update is available, a notification appears in the admin dashboard
3. You can apply the update with one click or via the CLI
4. During the update, the site briefly enters maintenance mode
5. The previous version is backed up for rollback if needed

## What an update replaces

An update installs the new release over the application folder **one top-level directory at a time**, not file by file. For each directory the release ships, the existing one is moved aside and the new one takes its place — so a folder you added inside one of them is removed along with its parent rather than merged.

This is why nothing you author should live inside the Total CMS folder.

| | On update |
|---|---|
| Anything **outside** the Total CMS folder — your pages, stylesheets, scripts, fonts, media | Never touched |
| `tcms-data/` | Never touched, wherever it lives |
| `.env`, `tcms.php`, `logs/`, `.git/` | Preserved, even inside the application folder |
| `cache/`, `tmp/` | Left alone — the release does not ship them |
| `config/`, `public/`, `resources/`, `src/`, `vendor/`, `autoload.php`, `.htaccess`, `.gitignore`, `version.json` | **Replaced in full** |

If you have added your own files inside any of those replaced directories — a `public/my-assets/` folder, for example — move them out of the Total CMS folder before updating. See [Installation](https://docs.totalcms.co/get-started/installation) for the recommended layout.

> This built-in updater applies to **zip installs**. Composer installs update through Composer — see below. The dashboard and `tcms update:apply` refuse to run on a Composer install and point you at `composer update`.

## Composer installs

If you installed with `composer create-project totalcms/totalcms`, update from the project root:

```bash
composer update totalcms/cms
php resources/bin/tcms cache:clear
```

Your project's `composer.json` requires `totalcms/cms: ^3.5`, so this picks up every 3.5.x release and stops before 4.0.

### Pre-release versions

The project skeleton ships with `"minimum-stability": "dev"` and `"prefer-stable": true`. That combination lets a fresh install reach a pre-release when no stable release exists yet, while always choosing the stable release when there is one.

So if you installed during the 3.5 beta or RC period — with or without `--stability=beta` — **there is nothing to change.** That flag only told Composer which version of the `totalcms/totalcms` skeleton to download; it is never written into your project, so it has no effect on later updates. Once 3.5.0 stable is published, `composer update totalcms/cms` moves you onto it and keeps you on stable releases from then on.

The one exception is a `composer.json` you edited yourself. If your `require` block pins a pre-release explicitly — `"totalcms/cms": "dev-develop"`, `"3.5.0-rc.21"`, or `"^3.5@beta"` — Composer keeps honoring that pin. Change it back to the default and update:

```bash
composer require totalcms/cms:^3.5
```

To confirm what you're actually running:

```bash
composer show totalcms/cms | head -3
```

## Checking for Updates

### Dashboard

Go to **Utilities > Update Manager** to see your current version and check for available updates. Click "Check for Updates" to force a fresh check.

### CLI

```bash
# Check for available updates
tcms update:check

# JSON output for scripting
tcms update:check --json
```

## Applying Updates

### Dashboard

1. Go to **Utilities > Update Manager**
2. Review the available version, severity, and changelog
3. Click "Update to X.X.X"
4. Confirm the update
5. Wait for the update to complete — the page reloads automatically

### CLI

```bash
# Apply the update (interactive confirmation)
tcms update:apply

# Skip confirmation
tcms update:apply --force

# JSON output
tcms update:apply --json
```

## Update Process

When an update is applied:

1. The update zip is downloaded from the update server
2. The zip is verified as a valid archive
3. The site enters **maintenance mode** — visitors see a "Updating..." page
4. The current application directory is backed up (e.g., `tcms.backup-3.2.2-20260410-143022/`)
5. The new files are extracted into place
6. All caches are cleared
7. Maintenance mode is disabled
8. The backup is removed and the update is logged to `tcms-data/.system/logs/totalcms.log` (channel `update`)

The entire process typically takes a few seconds.

The backup at step 4 exists only for the duration of the update. If any step fails, it is restored automatically and left in place for inspection; once the update completes successfully it is deleted. **Take your own backup before updating** if you want to be able to return to the previous version afterwards.

## Maintenance Mode

During an update, non-admin visitors see a static "Updating Total CMS" page with a 503 status code. The page auto-refreshes after 10 seconds.

Admin routes continue to work during maintenance so the update action can complete.

## Rollback

Rollback recovers from an update that **failed part-way through** — the backup it restores is the one taken during that update, and it is deleted once an update succeeds. There is nothing to roll back to after a successful update, and `tcms update:rollback` will report that no backup was found. To return to an earlier version after a successful update, restore your own backup.

### CLI

```bash
# Roll back to the previous version
tcms update:rollback

# Skip confirmation
tcms update:rollback --force
```

Rollback restores the backup directory left behind by a failed update. Only the most recent one is available, and only while an update has not since completed successfully.

### Manual Rollback

If the CLI isn't working after a failed update, you can manually swap directories:

```bash
# From the parent directory of your tcms installation
mv tcms tcms-failed
mv tcms.backup-3.2.2-20260410-143022 tcms
php tcms/resources/bin/tcms cache:clear
```

## Version Severity

Updates are classified by severity:

- **Patch** (e.g., 3.2.1 → 3.2.2) — Bug fixes and minor improvements. Safe to apply immediately.
- **Minor** (e.g., 3.2.x → 3.5.0) — New features and enhancements. Review the changelog before applying.
- **Major** (e.g., 3.x → 4.0) — Significant changes that may require attention. Read the upgrade guide.

## Troubleshooting

**"Already up to date"** — The update check is cached for 24 hours. Use `tcms update:check` to force a fresh check from the CLI.

**Update download fails** — Verify the server can make outbound HTTPS connections to `license.totalcms.co`. Check firewall rules and DNS resolution.

**Update fails mid-process** — The previous version is automatically backed up before the swap. Use `tcms update:rollback` or manually restore the backup directory.

**Site stuck in maintenance mode** — The maintenance flag is at `cache/maintenance.flag`. Delete it manually: `rm cache/maintenance.flag`

**Permission errors during update** — The PHP process needs write access to the application directory to perform the swap. Check file ownership and permissions.

## Version-Specific Upgrade Notes

Route shapes are stable across patch releases, but new minor versions occasionally consolidate endpoints or rearrange storage paths. Review the notes for any version you skip over.

### 3.2 → 3.5

3.5 consolidated several namespaces. The changes below are not auto-rewritten in your own templates, scripts, or WAF rules — review each.

**Content APIs moved under `/api/`.** Every content endpoint that used to live at the root (`/collections/...`, `/schemas/...`, `/upload/...`, `/cache/...`, `/report/...`, etc.) now lives under `/api/{same path}`. Update API clients, integrations, and webhook URLs.

A few previously-API endpoints intentionally moved **out** of `/api/` — `/sitemap`, `/feeds`, ImageWorks `download/` and `stream/` — because they're public-facing surfaces that don't fit the API auth/CORS model.

**Auth routes moved under `/admin/`.** Login, logout, register, forgot-password, and reset-password endpoints used to live at the root. They now live under `/admin/` (e.g. `/admin/login`, `/admin/logout`, `/admin/register/{collection}`). Update any custom login forms or links that posted to the old paths.

**Twig `{% include %}` paths now need a `templates/` prefix.** The Twig loader for stored templates was re-rooted from `tcms-data/templates/` to `tcms-data/builder/` so it can resolve both designer templates and Site Builder templates from one loader. Includes that used to resolve relative to the old root must add the new subdirectory:

```twig
{# 3.2 #}
{% include 'header.twig' %}

{# 3.5 #}
{% include 'templates/header.twig' %}
```

Templates referenced inside a collection schema's `template` field, or rendered through the public-API `template` parameter, follow the same rule.

**Whitelabel templates must live under `whitelabel/`.** During the upgrade, T3 migrates `tcms-data/templates/*` into `tcms-data/builder/templates/*`, except for files already under a `whitelabel/` subfolder, which move to `tcms-data/builder/whitelabel/*`. If you had whitelabel overrides saved **outside** that subfolder, the auto-migration won't pick them up — open them in the admin Templates UI and re-save under the `whitelabel/` folder so they take effect against the whitelabel chrome.
