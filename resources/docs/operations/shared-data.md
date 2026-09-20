---
title: "Shared Data Folders"
description: "Run several Total CMS installs against one tcms-data folder: what they share, what stays per-install, and what to watch out for."
audience: advanced
related:
  - operations/shared-data-cache
  - operations/shared-data-settings
  - operations/filesystem
---

# Shared Data Folders

Several Total CMS installs can point at one `tcms-data` folder. A set of
regional sites under one organization, a staging domain beside a live one, a
network of country sites sharing a member directory — all of them read and
write the same collections, schemas, objects and templates.

Point each install at the same folder in its own `config/tcms.php`:

```php
$settings['datadir'] = '/var/www/shared/tcms-data';
```

Everything below follows from that one line.

## What the installs share

- **Content** — collections, objects, schemas, templates, uploads
- **Settings** — `.system/settings.json`, unless a site declares otherwise
  (see [Per-Site Settings](shared-data-settings))
- **Users and sessions** — one user list, and one session store, which is what
  makes single sign-on across the sites possible
- **The job queue and automations** — one queue, drained by whichever site's
  cron runs
- **API keys, OAuth clients and grants, access groups**

## What stays per-install

- **The license.** Each domain is licensed separately.
- **The cache namespace**, unless you turn domain scoping off — you almost
  certainly should. See [Caching](shared-data-cache).
- **Compiled Twig templates**, in each install's own `cache/` directory.
- **Anything in that install's `config/tcms.php`** — including `siteId`,
  `siteSettings` and the sync remote.

## Giving one site its own settings

By default every install reads the same `settings.json`, so changing the
languages or the site name on one changes them on all. To let a site keep its
own, see [Per-Site Settings](shared-data-settings).

## Known limitations

These are real and unfixed. Read them before planning a large deployment.

**Migrations run once for the folder.** The ledger at
`.system/migrations.json` is shared, so a migration acts on whichever install
boots first. For migrations that touch a per-site collection — anything keyed
off `builder.pagesCollection`, for instance — the other installs are marked
done without having run. After an update that ships a migration, check the
other sites rather than assuming.

**Queued jobs may run under another site's configuration.** Jobs carry no
record of which site created them, and the queue lock covers the whole folder,
so whichever site's cron wins drains everyone's jobs using its own domain,
URL, locale and mailer identity. Generated links and outgoing mail can carry
the wrong site's identity. If that matters, run cron on one install only and
accept that its identity is the one jobs get.

**Dev mode is folder-wide.** Turning it on for one install turns it on for all
of them.

**Shared collections have one URL.** A collection rendered by several sites
has a single `url` setting, so canonical tags, sitemap entries and RSS links
for that content all point at one domain regardless of which site rendered it.
Give a collection to one site, or accept the shared canonical.

**Access groups gate collections, not domains.** A user allowed a collection
is allowed it on every install sharing the folder. With single sign-on that
means a session minted on one site is valid on all of them. Anything you gate
per site must be gated by collection, not by which domain the request hit.

**Editing a Site Builder template needs every cache cleared.** Templates live
in the shared folder but compile into each install's own `cache/`, so the
others keep serving the old compile until their cache is cleared.
