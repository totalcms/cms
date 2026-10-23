# Troubleshooting and reporting a Total CMS bug

> Read this **before telling the user something is a Total CMS bug.** Most
> problems that look like bugs come from how T3 is being used, configured or
> hosted, and the fix is on this side. Only a confirmed T3 bug gets a report,
> and the report goes by email to **support@totalcms.co**.

## 1. Rule out the usual suspects

Work through the ones that match the symptom. Each has caught real "bugs".

| Symptom | Likely cause | Check / fix |
|---|---|---|
| Behavior contradicts this skill | This skill copy is older than the CMS | `vendor/bin/tcms skill:install --check` |
| A template or schema edit has no effect | Stale cache | `vendor/bin/tcms cache:clear`; restart `php -S` after adding page records |
| An object is missing from admin tables, queries or the sitemap | JSON written straight into `tcms-data/` (no index entry) | `vendor/bin/tcms repair:index <collection>`, then write through the CLI from now on |
| A whole collection errors or shows nothing | Invalid JSON in a hand-edited file | Validate the files with `jq`, fix, then `repair:index` |
| Imports fail with `The required properties (id) are missing` | The schema lacks an `id` property | Add it; `vendor/bin/tcms schema:lint <id> --strict` |
| Deck import rejected | Deck sent as an array, or a key with a hyphen | Decks are dictionaries keyed by item `id`, letters/numbers/underscores only |
| Every save fails with 400 "installation has been corrupted" | Files inside the CMS were modified | `vendor/bin/tcms check` (Integrity). Undo the edit; never change CMS files |
| Builder page 404s on the dev server | `php -S` started without the router script | `php -S localhost:8080 -t public public/index.php` |
| Links and rewrite rules disagree on pretty URLs | Collection URL set to a `.php` page, not the folder | Set the collection URL to the folder (`/blog/`) |
| Works locally, 403s or silently fails on a shared host | A web application firewall (WAF) or CDN in front of the site | Blocked DELETE requests or AI user agents. `vendor/totalcms/cms/resources/docs/mcp/troubleshooting.md` |
| A feature is missing or refuses with a license/edition message | Edition-gated feature, or an expired trial | `vendor/bin/tcms info` shows the edition and license |
| Something broke right after installing or updating an extension | The extension, not core | `vendor/bin/tcms extension:disable <id>` and retry |

Then check `vendor/bin/tcms check`: it reports integrity, license, PHP
extensions and limits, permissions, caches and sessions. A ✗ or ⚠️ there is
usually the answer.

**Command line vs web server:** `check` runs on the command-line PHP. The web
server runs its own PHP, with its own extensions, limits and cache. If the
problem only happens in the browser, ask the user for **Admin → Utilities →
Server Checker** as well.

## 2. Read the log

The browser shows a generic error page unless `debug` is on, but the cause is
written to the log. Look at the newest file of the matching log:

- **Composer install:** `logs/` at the project root
- **Zip install:** `../tcms-data/.system/logs/` (beside the CMS folder)
- `$settings['logger']['path']` in `config/tcms.php` overrides both

| Log | Holds |
|---|---|
| `totalcms.log` | General application errors, the default place to look |
| `twig.log` | Template and Twig rendering errors |
| `extensions.log` | Extension register/boot failures and quarantine |
| `jobs.log` / `importer.log` | Queued jobs, imports |
| `email.log` | Mail delivery |
| `mcp.log` | MCP server and OAuth |
| `access.log` | Logins and access denials |
| `license.log` | License validation |

Search for `ERROR` and `CRITICAL` around the time of the failure.

## 3. Check the docs for this version

The on-disk docs (`vendor/totalcms/cms/resources/docs/`) match the installed
version. Find the page for the feature and compare. "The docs say X, the CMS
does Y" is good evidence of a real bug. "I expected X" with no docs behind it
may be a feature request, which is fine to send too, labeled as one.

## 4. Reduce it to a minimal reproduction

Cut the case down to the smallest schema, template, object or command that
still fails. Try it on a fresh object or a throwaway collection. If it stops
failing as you remove things, the last thing removed is the clue, and often
the fix.

## 5. Decide what it is

- **Misuse** (wrong JSON shape, files written by hand, a wrong option): fix it.
- **Configuration** (`config/tcms.php`, collection settings, edition): fix it or
  explain the setting to the user.
- **Hosting/environment** (PHP version or extensions, WAF, permissions, rewrite
  rules): explain what the host needs to change.
- **Total CMS bug**: write the report below.

## The report

Write it as one Markdown file the user can attach or paste into an email to
**support@totalcms.co**. Use this shape:

````markdown
# <one-line summary of the bug>

## What happens
<exact error text, copied, not paraphrased>
<what the user or agent was doing when it happened>

## Expected
<what should happen, with the docs page that says so if there is one>

## Steps to reproduce
1. …
2. …

<the minimal schema / template / object / command, trimmed to what still fails>

## Already ruled out
<which checks from the troubleshooting list were run, and what they showed>

## Log excerpt
```
<the ERROR/CRITICAL lines and the stack trace, from the log that matches>
```

## Environment
<output of: vendor/bin/tcms check --json --config>

Install layout: Composer | zip
Extensions: <output of: vendor/bin/tcms extension:list --json>
Browser-only problem: yes/no (if yes, Admin → Utilities → Server Checker attached)
````

**Never include** license keys, API keys, passwords, OAuth secrets, SMTP
credentials or other people's personal data. `check --config` already redacts
secrets, so send that rather than `config/tcms.php`. Replace real customer
content in samples with placeholders. Read the finished report once for
anything secret before handing it to the user.

Tell the user where the file is and to email it to support@totalcms.co.
