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

	// URL prefix where the API is mounted. Default: '/api'
	// 'api' => '/api',
];
```

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
	'dashboard' => [
		'title' => "Joe's Bistro Admin",
	],
	'cache' => [
		'redis' => false,
	],
];
```

The merge and type-safety handling lives in `TotalCMS\Support\Config`. Each settings block is validated with `is_array()` before it is applied, so a malformed override falls back to the default rather than breaking the app.

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

For the authoritative, fully-commented list of every setting and its default, read `config/defaults.php` in your installation. Anything you can set there can be overridden by returning the same key from `config/tcms.php`.
