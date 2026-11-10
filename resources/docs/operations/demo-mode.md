---
title: "Demo Mode"
description: "Put a Total CMS site online as a read-only demo: anyone can open the admin without signing in, and nothing they do is saved."
related:
  - operations/configuration
  - operations/security
  - site-builder/starters
---

# Demo Mode

Demo mode turns a site into a showroom. Anyone can open the admin without
signing in and look at everything: collections and their content, schemas,
Site Builder pages and templates, settings and utilities. Nothing they do is
saved. Use it to put a starter or a finished project online so people can see
how the site is put together before they build their own.

## Turning it on

Demo mode is set in `config/tcms.php`, never in the admin, so nobody browsing
the demo can turn it off:

```php
<?php

return [
	'demo' => [
		'enable' => true,
	],
];
```

Only `true` turns it on. Remove the setting, or set it to `false`, to put the
site back to normal.

## What visitors see

- **No sign-in.** Going to `/admin` opens the dashboard. Sign-in is off for
  the whole site while demo mode is on, as if `auth.enable` were `false`, so
  visitors see the admin as an administrator would.
- **A banner on every admin page** saying the site is a demo and changes are
  not saved.
- **Forms work until Save.** A visitor can open any form and type into it.
  Save, Delete and uploads answer with "This site is in demo mode, so saving
  is disabled."
- **The Log Analyzer is hidden.** The logs hold the IP addresses of the
  people who visit, and on a public demo those are other visitors. The page,
  its download, and its links in the menus and quick actions are all gone.
  The Activity Log stays: it records what changed, and nothing changes.

## What is refused

Every request that could change something is refused, which on the web means
every request other than `GET`, `HEAD` and `OPTIONS`. The rule is the
request's method, not a list of pages, so it covers all of these without
exception:

- saving, deleting and uploading, in the admin and through the REST API
- imports, JumpStart, `tcms push`, cache clears, and running jobs or
  automations from the admin
- the Twig Playground and the Twig Debugger, since their renders run code on
  the server
- the forms on the site itself, such as contact forms, registrations and
  ratings
- the MCP server, whose requests are all `POST`, reads included

The admin's API calls get the message as JSON (`{"error": "…"}`) with a `403`,
HTMX requests get it as a box swapped in where the result would have gone, and
a plain form post gets the access-denied page.

## What demo mode does not stop

Demo mode guards the web requests. It does not change what runs on the server
itself:

- **The `tcms` CLI and cron jobs still write.** If the server runs
  `jobs:process` or `automations:process` on a schedule, scheduled automations
  still run. A demo usually has no reason to run them, so leave the cron lines
  out.
- **Reads still have side effects.** Image renders fill the image cache,
  visits open sessions, and the [emergency cache URLs](docs/operations/security)
  work as on any site. None of these change the site's content.

## Putting a starter online

1. Install the starter on the server the usual way.
2. Add the `demo` setting to `config/tcms.php`.
3. Leave out the cron lines for jobs and automations.
4. Open `/admin` in a private window and check that the banner shows and Save
   is refused.

To change the demo later, turn demo mode off, make the change, and turn it
back on, or make the change on a copy and deploy it.
