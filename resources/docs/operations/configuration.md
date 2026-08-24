---
title: "Configuration"
description: "Configure Total CMS with the config/tcms.php bootstrap file, the deep-merge override model, real settings keys, the logger path, and reading config in Twig."
---

# Configuration

Most of Total CMS is configured from the **Admin UI** (the Settings page) — domains, email, image presets, auth, dashboard appearance, and so on are all stored in your data directory and edited from the browser. You only need a config file for the handful of **bootstrap** settings that have to be known before the data directory is loaded, or to override a default that the admin UI doesn't expose.

## Where Configuration Lives

Total CMS ships two PHP files in `config/`:

- **`config/defaults.php`** — the base settings array for the whole application. This file is part of the codebase and is replaced on updates. **Do not edit it.**
- **`config/tcms.php`** — your optional override file. It does not exist by default. To create it, copy the sample:

```bash
cp config/tcms-sample.php config/tcms.php
```

The sample is intentionally tiny. Out of the box it returns an empty array and documents only the two bootstrap keys you might need:

```php
<?php

declare(strict_types=1);

// Total CMS Bootstrap Configuration
// Only path settings that are needed before the data directory is loaded.
//
// NOTE: Most settings should be configured via the Admin UI (Settings page).
return [
	// Data directory location. Default: document root + /tcms-data
	// 'datadir' => __DIR__ . '/tcms-data',

	// URL prefix the app is mounted at. Empty at the domain root; set it for
	// a subfolder install, e.g. '/rw_common/plugins/stacks/tcms'.
	// 'api' => '',
];
```

### Which `config/` directory?

On a **Composer** install this is the `config/` directory in your project root, next to `composer.json` — not the one inside `vendor/totalcms/cms/`. On a **zip or Stacks** install there is only one `config/` directory, inside the Total CMS folder, and that is the one.

Zip and Stacks installs have a second override file: **`tcms.php` in your document root**. It uses the same returned-array format and is merged *after* `config/tcms.php`, so it wins. This is the file the setup wizard and the admin Settings screens write to, which is why an override you put in `config/tcms.php` can appear to be ignored — check the document-root file for a competing key before assuming the setting is broken.

## The Deep-Merge Override Model

Whatever array you return from `config/tcms.php` is **deep-merged** over `config/defaults.php`. You only specify the keys you want to change — nested arrays merge rather than replace, so you can override a single sub-key without restating the whole block.

For example, to keep all of the default `auth` settings but raise the maximum login attempts and turn off passkeys:

```php
<?php

return [
	'auth' => [
		'maxAttempts' => 5,
		'usePasskeys' => false,
	],
];
```

Every other key under `auth` (`loginWith`, `collection`, `persistentLoginDays`, and so on) keeps its default value. The same pattern works for any nested settings block:

```php
<?php

return [
	'datadir' => '/var/www/shared/tcms-data',
	'logger'  => [
		'level' => Monolog\Level::Debug,
	],
	'cache' => [
		'redis' => false,
	],
];
```

The merge and type-safety handling lives in `TotalCMS\Support\Config`. Each settings block is validated with `is_array()` before it is applied, so a malformed override falls back to the default rather than breaking the app.

### Precedence: the admin UI wins

`config/tcms.php` is not the last word. Settings are merged in this order, each layer overriding the one above it:

1. `config/defaults.php` — the shipped defaults
2. **`config/tcms.php`** — your override file
3. `DOCUMENT_ROOT/tcms.php` — installation-level overrides (mainly `datadir`)
4. **`tcms-data/.system/settings.json`** — everything saved from the admin Settings pages

Layer 4 is last, so **a setting saved in the admin beats the same key in `config/tcms.php`**. This is deliberate: a value an operator changed in the browser should not be silently reverted by a file they may not know exists.

The practical rule: **if a setting has a control in the admin, change it there.** Setting it in `config/tcms.php` will appear to do nothing — the file still loads and its other keys still apply, so there is no error to notice, which makes this a genuinely confusing few minutes if you do not know the order.

Precedence applies per key, not per block. `settings.json` contains only the fields the admin Settings pages actually expose and you have saved, so a nested key the UI doesn't manage still takes your file's value even when a sibling key does not. To find out whether a given setting is already UI-managed, look it up in your site's `tcms-data/.system/settings.json`:

```bash
grep -n publicIpPerMinute tcms-data/.system/settings.json
```

A hit means the admin owns that setting — go and change it there.

## What Lives in the Bootstrap File

These are the keys you are most likely to set in `config/tcms.php`. They all exist in `config/defaults.php` — refer to that file for the complete list and the inline comments that document each one.

- **`datadir`** — absolute path to your `tcms-data` directory. Auto-detected by default (document root, or its parent if a `tcms-data` folder exists there). Set this explicitly for custom layouts or shared data directories.
- **`api`** — URL prefix where the front controller is mounted. Empty at the domain root, or a subpath like `/cms` for subfolder installs.
- **`domain`** — the site domain. Auto-detected from the request `Host` header, but behind Docker or a reverse proxy that doesn't forward `Host`, set it explicitly so licensing and link-building resolve correctly.
- **`debug`** — `false` in production; set `true` for development.
- **`logger`** — log channel name, level, retention, and path (see below).
- **`session`** — cookie name, lifetime, SameSite, secure flag, and garbage-collection lifetime.
- **`cache`** — backend toggles (`apcu`, `redis`, `memcached`, `filesystem`) plus Redis/Memcached connection details and fragment-cache settings.

### Logger Path

The logger's `path` is empty in `defaults.php` on purpose — Total CMS resolves it **after** the merge so a `datadir` override is respected:

- **Composer installs** → `logs/` at the project root (survives `composer update`).
- **Zip installs** → `<datadir>/.system/logs/` (survives the application-directory swap during updates).

To override the location entirely, set it in `tcms.php`:

```php
<?php

return [
	'logger' => [
		'path'  => '/var/log/totalcms',
		'level' => Monolog\Level::Warning,
	],
];
```

## Reading Configuration in Twig

Configuration is exposed in templates through the `cms` global, using `cms.config()`. There is **no** `config` global.

`cms.config()` takes the settings block as the first argument and an optional sub-key as the second:

```twig
{# The whole auth block #}
{{ cms.config('auth') }}

{# A single sub-key #}
{{ cms.config('auth', 'collection') }}
{{ cms.config('dashboard', 'title') }}
```

Common scalar settings are also available directly on `cms`:

```twig
{{ cms.env }}
{{ cms.domain }}
{{ cms.url }}
```

## Configuration Reference

For the authoritative, fully-commented list of every setting and its default, read `config/defaults.php` in your installation. Any key you find there can be overridden by returning the same key from `config/tcms.php` — with one exception worth remembering: if the admin UI exposes that setting and you have saved it there, the saved value wins. See [Precedence: the admin UI wins](#precedence-the-admin-ui-wins).
