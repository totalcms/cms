---
title: "Updates"
description: "Keep Total CMS up to date with one-click dashboard updates, CLI commands, or manual upgrades. Includes rollback and maintenance mode details."
---

# Updates

Total CMS includes a built-in update system that checks for new versions and applies them with minimal downtime.

## How Updates Work

1. Total CMS periodically checks the license server for new versions (cached for 24 hours)
2. When an update is available, a notification appears in the admin dashboard
3. You can apply the update with one click or via the CLI
4. During the update, the site briefly enters maintenance mode
5. The previous version is backed up for rollback if needed

Updates only replace the application files (`tcms/`). Your content in `tcms-data/` is never touched.

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
8. The update is logged to `tcms-data/.system/updates.log`

The entire process typically takes a few seconds.

## Maintenance Mode

During an update, non-admin visitors see a static "Updating Total CMS" page with a 503 status code. The page auto-refreshes after 10 seconds.

Admin routes continue to work during maintenance so the update action can complete.

## Rollback

If an update causes issues, you can roll back to the previous version:

### CLI

```bash
# Roll back to the previous version
tcms update:rollback

# Skip confirmation
tcms update:rollback --force
```

Rollback restores the backup directory that was created during the update. Only the most recent backup is available.

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
- **Minor** (e.g., 3.2.x → 3.3.0) — New features and enhancements. Review the changelog before applying.
- **Major** (e.g., 3.x → 4.0) — Significant changes that may require attention. Read the upgrade guide.

## Troubleshooting

**"Already up to date"** — The update check is cached for 24 hours. Use `tcms update:check` to force a fresh check from the CLI.

**Update download fails** — Verify the server can make outbound HTTPS connections to `license.totalcms.co`. Check firewall rules and DNS resolution.

**Update fails mid-process** — The previous version is automatically backed up before the swap. Use `tcms update:rollback` or manually restore the backup directory.

**Site stuck in maintenance mode** — The maintenance flag is at `cache/maintenance.flag`. Delete it manually: `rm cache/maintenance.flag`

**Permission errors during update** — The PHP process needs write access to the application directory to perform the swap. Check file ownership and permissions.
