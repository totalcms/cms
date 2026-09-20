---
title: "Per-Site Settings"
description: "Give each install its own value for chosen settings sections while sharing one tcms-data folder."
audience: advanced
related:
  - operations/shared-data
  - operations/shared-data-cache
  - operations/filesystem
---

# Per-Site Settings

Several Total CMS installs can [share one data folder](shared-data-cache). They
then share `settings.json` too — so a change to Languages, or the site name, or
the SMTP sender on any one of them changes it on all of them.

Usually that is the point. When it is not — a set of regional sites that each
need their own languages, say — give each install a `siteId` and it gets its
own overlay file for the sections you choose.

## Setting it up

In each install's `config/tcms.php`, name the site and list the settings
sections it owns:

```php
$settings['siteId']       = 'italy';
$settings['siteSettings'] = ['i18n', 'general'];
```

`siteId` must be a plain slug — lowercase letters, digits and hyphens.
Anything else is ignored.

That is the whole setup. There is no file to create: the sections you list are
read from, and saved to, `tcms-data/.system/settings-italy.json`, which is
created the first time you save one of them. Everything else — SMTP, cache,
MCP, the 404 path — stays in the shared `settings.json`.

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

## Changing what a site owns

Edit `siteSettings` in that install's `config/tcms.php` and redeploy.

Removing a section returns the site to the shared value immediately — the
overlay may still hold the old value on disk, but an unlisted section is not
read, so it has no effect. Adding the section back picks that value up again.

## Which section is which

Each settings page says whether it is site-specific — and which file it saves
to — or shared. That line only appears when `siteId` is set.

## Sections to leave shared

Some settings look per-site but are backed by state the whole folder shares:

- **Cache** — `Scope Cache by Domain` must be off, and the same, on every
  install. Diverge it and the installs stop invalidating each other's entries.
- **OAuth** — the token and client stores are shared, so differing lifetimes
  across sites are confusing rather than useful.
- **Auth** — `Public Registration Group` is a reasonable per-site setting; the
  `Default User Collection` is not, if your sites share one set of users.
- **License**, **Extensions** — no per-site meaning. Extension enablement is
  not a setting; it lives elsewhere in the data folder.

Listing one of these is not blocked, but nothing good comes of it.

## Sync is different

`tcms push` / `tcms pull` read their remote from `sync` in `config/tcms.php`
rather than an overlay, so a deploy key never has to live in the shared data
folder at all. See [Sync](sync).
