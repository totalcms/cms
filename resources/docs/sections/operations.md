---
title: "Operations"
description: "Run Total CMS in production — deployment, security, updates, sync, and infrastructure tuning."
---

# Operations

This section is for sysadmins, devops engineers, and anyone responsible for keeping a Total CMS site running. If you're building a site, you can probably skip most of this until you're ready to deploy.

## Going to production

- **[Deployment](docs/advanced/deployment)** — Web servers, file permissions, environment variables, OPcache.
- **[Nginx](docs/advanced/nginx)** — Configuration recipe.
- **[Server Sizing](docs/advanced/server-sizing)** — Memory, CPU, and disk guidance.
- **[Security](docs/advanced/security)** — CSP, CORS, file upload validation, path traversal prevention, session hardening.

## Maintenance

- **[Updates](docs/advanced/updates)** — How to update Total CMS safely; the `tcms update:*` commands.
- **[Sync](docs/advanced/sync)** — Move content between staging and production with `tcms pull` / `tcms push`.
- **[JumpStart](docs/advanced/jumpstart)** — Bulk export/import for backups and project seeding.
- **[Licenses](docs/advanced/licenses)** — License validation, edition gates, trial expiry.

## Architecture

- **[Filesystem](docs/advanced/filesystem)** — Where Total CMS stores data on disk.
- **[Search Backends](docs/advanced/search)** — The cache hierarchy (APCu → Redis → Memcached → Filesystem) and how it affects search.

## Migrating

- **[Migration from v1](docs/advanced/migration-total-cms-one)** — Move a Total CMS 1 site to Total CMS 3.
