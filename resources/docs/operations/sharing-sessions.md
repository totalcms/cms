---
title: "Sharing Sessions with Other PHP Apps"
description: "Pass data between Total CMS pages and other PHP applications on the same site through $_SESSION: where Total CMS stores sessions, the one rule every app must follow, and how to diagnose a split session."
related:
  - apis/php-api
  - operations/security
  - operations/configuration
  - twig/variables
---

# Sharing Sessions with Other PHP Apps

A site rarely runs Total CMS alone. A shopping cart, a membership system, a
form processor, or a script of your own often sits beside it on the same
domain, and the natural way to hand data between them is PHP's `$_SESSION`.
Total CMS supports this. There is one rule, and everything else on this page
follows from it:

> **Every script that shares the session cookie must open its session in the
> same folder Total CMS uses.**

## Where Total CMS keeps sessions

Total CMS stores session files in `tcms-data/.system/sessions`, not in PHP's
default location. The admin, the REST API, and pages built with the
[PHP API](docs/apis/php-api) all use that folder. The reasons are covered under
[where session files are stored](docs/operations/security#where-session-files-are-stored):
PHP's default folder is shared with every other site on the server, and a
neighbour's cleanup can delete your session files after as little as 24
minutes.

The absolute path is your `datadir` setting plus `/.system/sessions`. On a
typical install that is something like:

```
/home/example/public_html/tcms-data/.system/sessions
```

## Point the other app at the same folder

PHP decides where a session lives from `session.save_path` at the moment
`session_start()` runs. Set it before that call, in whichever of these places
fits the app.

### Site-wide (recommended)

Setting the path for the whole site covers every script at once, including
pages that open a session before Total CMS loads. Under PHP-FPM or CGI, which
is what most hosts run, add a `.user.ini` file to the document root:

```ini
session.save_path = /home/example/public_html/tcms-data/.system/sessions
```

PHP re-reads `.user.ini` every five minutes by default, so allow a moment for
the change to take effect. Under Apache with `mod_php`, use `.htaccess`
instead:

```apache
php_value session.save_path /home/example/public_html/tcms-data/.system/sessions
```

If you control the PHP-FPM pool, the same setting works in `www.conf` as
`php_admin_value[session.save_path]`.

### In the other app's configuration

Most applications have a configuration file that loads before they start the
session. One line there is enough:

```php
@ini_set('session.save_path', '/home/example/public_html/tcms-data/.system/sessions');
```

For example, VibraCart Pro loads `vcconfig.php` before every `session_start()`,
so the line belongs in the editable block at the top of that file, beside its
`$vcSessionName` setting.

### Move Total CMS instead

If the other app cannot be changed, Total CMS can use PHP's default folder
again:

```php
$settings['session']['save_path'] = '/var/lib/php/sessions';
```

Use the path your host's `php -i` reports for `session.save_path`. This gives
up the protection against neighbouring-site cleanup described above, so prefer
one of the first two options when you can.

## What happens when the folders differ

The browser sends one session cookie, so both apps look for the same session
ID, but each looks in its own folder. The result is one ID with two separate
stores, and data one app writes never appears in the other. A page that sets
`$_SESSION['user']` for the cart does so in Total CMS's folder, and the cart
reads its own, older copy from PHP's default folder.

Total CMS leaves the other app's file where it found it, so the other app
keeps working with its own data and the session ID stays valid everywhere.
That matters for apps that turn on `session.use_strict_mode` before
`session_start()`: strict mode rejects an ID that has no file behind it and
issues a new one, which would log the visitor out of Total CMS on the next
page. Releases before 3.6.3 deleted the file, and on sites running such an app
the symptom was a login that did not stick and a cart that emptied itself
between pages.

To confirm a split session, take the `PHPSESSID` value from the browser and
look for `sess_<id>` in both folders. If each folder has its own copy, the
apps are not sharing a store. Total CMS also says so in its log: whenever a
page opened the session in a folder other than Total CMS's, it writes an
info-level `Session relocated from the host's store` line naming both paths.
A conflict inside one shared folder is routine and is logged at debug only.

## Rules for shared data

- **Keys under `totalcms.` are reserved.** Total CMS keeps its own state, such
  as the logged-in user, under that prefix. Do not read or write those keys.
  Anything else in `$_SESSION` is yours.
- **A session opened before Total CMS loads is carried over.** When a page
  calls `session_start()` before `new TotalCMS()`, the constructor closes that
  session and reopens it under the same ID. With the default
  `conflictStrategy` of `preserve`, every key the page had already set survives.
  Setting `$settings['session']['conflictStrategy']` to `replace` drops them
  instead.
- **Writes after the constructor go to the same session.** Anything a page or
  stack sets in `$_SESSION` after Total CMS has started is visible to any other
  app pointed at the same folder on its next request.
- **Twig can read shared keys.** Use `sessionData.key`, or
  `sessionData['key-with-hyphens']`, as described under
  [Twig variables](docs/twig/variables#session-data).
