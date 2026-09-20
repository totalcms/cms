---
title: "Shared Data Folders"
description: "Run several Total CMS installs against one tcms-data folder: what they share, what stays per-install, caching, and per-site settings overlays."
audience: advanced
updated: 2026-09-20
related:
  - operations/filesystem
  - operations/deployment
  - operations/sync
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
  (see [Per-site settings](#per-site-settings) below)
- **Users and sessions** — one user list, and one session store, which is what
  makes single sign-on across the sites possible
- **The job queue and automations** — one queue, drained by whichever site's
  cron runs
- **API keys, OAuth clients and grants, access groups**

## What stays per-install

- **The license.** Each domain is licensed separately.
- **The cache namespace**, unless you turn domain scoping off — you almost
  certainly should. See [Caching](#caching) below.
- **Compiled Twig templates**, in each install's own `cache/` directory.
- **Anything in that install's `config/tcms.php`** — including `siteId`,
  `siteOverrides` and the sync remote.

## Caching

This is the one setting almost every shared-folder deployment needs to
change.

### The problem

By default each install namespaces its cache entries by domain. That keeps
unrelated sites on one server from colliding in a shared APCu pool or Redis
instance. When installs share a data folder, though, it means they cache the
same content twice under different names — and a save through one install
clears only its own entries. The other installs keep serving what they cached
until the entry expires, which can be hours.

### The setting

In **Settings → Cache**, turn **Scope Cache by Domain** off.

The setting lives in `tcms-data/.system/settings.json`, which is itself
shared, so changing it in one install applies it to every install on that
data folder.

With it off, cache entries are namespaced by the data folder instead of the
domain. Installs sharing a folder share one namespace, and a save in any of
them invalidates all of them. Unrelated installs with their own data folders
stay isolated, so a shared Redis instance is still safe.

### What stays separate

Three kinds of cached data remain per-domain whatever this setting says:

- **License validation.** Each domain is licensed separately.
- **Sessions.**
- **Password reset tokens.**

### Where the cache is stored

With domain scoping on, cache entries live in each install's `cache/`
directory. With it off, they move to `tcms-data/.system/cache` so the disk
layer is shared too. Nothing else moves — each install keeps compiling its
own templates into its own `cache/` directory, because those belong to the
install's code rather than to the shared content.

Clearing the cache from one install's admin clears the shared entries for
every install, plus that install's own compiled templates. If you edit a Site
Builder template stored in `tcms-data` through one install's admin, clear the
cache on the other installs as well so they recompile it.

### Turning it back on

Switching the setting either way clears the cache first, so nothing stale is
left behind under the old namespace. Expect the first few requests afterwards
to be slower while the cache refills.

## Per-site settings

By default every install reads the same `settings.json`, so changing the
languages, or the site name, or the SMTP sender on any one of them changes it
on all of them.

Usually that is the point. When it is not — a set of regional sites that each
need their own languages, say — give each install a `siteId` and it gets its
own overlay file for the sections you choose.

### Setting it up

In each install's `config/tcms.php`, name the site and list the settings
sections it owns:

```php
$settings['siteId']        = 'italy';
$settings['siteOverrides'] = ['i18n', 'general'];
```

`siteId` must be a plain slug — lowercase letters, digits and hyphens.
Anything else is ignored.

That is the whole setup. There is no file to create: the sections you list
are read from, and saved to, `tcms-data/.system/settings-italy.json`, which
is created the first time you save one of them. Everything else — SMTP,
cache, MCP, the 404 path — stays in the shared `settings.json`.

Until you save a listed section, the site reads the shared value for it. So
adding a section to the list changes nothing on its own; it changes where the
*next* save goes.

## Sections are owned whole

Listing a section makes all of it site-local, not just the field you cared
about. List `general` because this site needs its own name and timezone, and
the first save of that page also takes `notfound`, `sentry` and the rest of
the General fields out of the shared file for this site. Same for `smtp` and
all eleven of its fields.

Decide per section, not per field.

One consequence of the whole-section swap: if the shared file's section
carries a key its schema no longer declares, the settings form never posts
it, so the first save of that section on this site leaves it out of the
overlay — and from then on it is shadowed, not inherited, because the site
reads its own overlay for the whole section. There is no CLI to seed the
overlay first, so preserving such a key means copying it into the overlay
file by hand before the first save.

## Changing what a site owns

Edit `siteOverrides` in that install's `config/tcms.php` and redeploy.

Removing a section returns the site to the shared value immediately — the
overlay may still hold the old value on disk, but an unlisted section is not
read, so it has no effect. Adding the section back picks that value up again.

## Which section is which

Each settings page says whether it is site-specific — and which file it saves
to — or shared. That line only appears when `siteId` is set.

## Sections to leave shared

Some settings look per-site but are backed by state the whole folder shares:

- **Cache** — `Scope Cache by Domain` must be off, and the same, on every
  install. Diverge it and the installs stop invalidating each other's
  entries.
- **OAuth** — the token and client stores are shared, so differing lifetimes
  across sites are confusing rather than useful.
- **Auth** — `Public Registration Group` is a reasonable per-site setting;
  the `Default User Collection` is not, if your sites share one set of users.
- **License**, **Extensions** — no per-site meaning. Extension enablement is
  not a setting; it lives elsewhere in the data folder.

Listing one of these is not blocked, but nothing good comes of it.

## Sync is different

`tcms push` / `tcms pull` read their remote from `sync` in `config/tcms.php`
rather than an overlay, so a deploy key never has to live in the shared data
folder at all. See [Sync](sync).

## Known limitations

These are real and unfixed. Read them before planning a large deployment.

**Migrations run once for the folder.** The ledger at
`.system/migrations.json` is shared, so a migration acts on whichever install
boots first. For migrations that touch a per-site collection — anything keyed
off `builder.pagesCollection`, for instance — the other installs are marked
done without having run. After an update that ships a migration, check the
other sites rather than assuming.

**Queued jobs may run under another site's configuration.** Jobs carry no
record of which site created them, and the queue lock covers the whole
folder, so whichever site's cron wins drains everyone's jobs using its own
domain, URL, locale and mailer identity. Generated links and outgoing mail
can carry the wrong site's identity. If that matters, run cron on one install
only and accept that its identity is the one jobs get.

**Dev mode is folder-wide.** Turning it on for one install turns it on for
all of them.

**Shared collections have one URL.** A collection rendered by several sites
has a single `url` setting, so canonical tags, sitemap entries and RSS links
for that content all point at one domain regardless of which site rendered
it. Give a collection to one site, or accept the shared canonical.

**Access groups gate collections, not domains.** A user allowed a collection
is allowed it on every install sharing the folder. With single sign-on that
means a session minted on one site is valid on all of them. Anything you
gate per site must be gated by collection, not by which domain the request
hit.

**Editing a Site Builder template needs every cache cleared.** Templates
live in the shared folder but compile into each install's own `cache/`, so
the others keep serving the old compile until their cache is cleared.

**The setup wizard writes straight to the shared file.** An install that
presets `datadir` in `tcms.php` and skips the wizard is unaffected — but if
an operator instead joins a second (or later) install *through* the wizard's
data-path step, it writes `siteName` and the default locale directly to
`.system/settings.json`, bypassing `siteId`/`siteOverrides` entirely. That
overwrites the shared site name and default locale for every install on the
folder.
