---
title: "Total CMS Security Guide"
description: "Secure your Total CMS installation with tcms-data folder protection, HTTPS setup, HTML and SVG sanitization, upload restrictions, and security headers."
---

# Total CMS Security Guide

This guide provides essential security recommendations for protecting your Total CMS installation and data.

## Protecting the tcms-data Folder

The `tcms-data` folder contains all your CMS content, including potentially sensitive information (API keys, user data, collection data). It's crucial to protect this directory from unauthorized web access.

**Automatic Apache Protection**: Total CMS automatically creates a `.htaccess` file in the `tcms-data` folder to deny direct web access when using Apache. If you're using Nginx or another web server, you must configure protection manually (see below).

### Best Practice: Move Outside Document Root

The most secure approach is to relocate the `tcms-data` folder outside your web server's document root:

1. **Move the folder** to a location outside your public web directory
   ```bash
   # Example: Move from /var/www/html/tcms-data to /var/www/tcms-data
   mv /var/www/html/tcms-data /var/www/tcms-data
   ```

2. **Update the configuration** in the Admin Dashboard:
   - Navigate to Admin → Settings
   - Update the "Data Directory" field to the new location
   - Save the settings

3. **Ensure proper permissions**:
   ```bash
   chmod -R 755 /var/www/tcms-data
   chown -R www-data:www-data /var/www/tcms-data  # Adjust user/group as needed
   ```

### Alternative: Restrict Access Within Document Root

If you must keep `tcms-data` within the document root, configure your web server to block access.

#### Apache (.htaccess)

**Automatic Protection**: Total CMS automatically creates a `.htaccess` file inside the `tcms-data` folder when it's first initialized. This file denies all direct web access to the folder contents.

The auto-generated `.htaccess` file contains:
```apache
# Deny direct access to all files and folders in tcms-data
# This protects sensitive data including API keys, collections, and user data

<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>
```

**Alternative Approach**: You can also add this to your root `.htaccess` file:
```apache
# Define 404 page
ErrorDocument 404 /404/

# Block access to tcms-data directory
RedirectMatch 404 ^/tcms-data/
```

#### Nginx

**Required for Nginx Users**: Unlike Apache, Nginx does not process `.htaccess` files. You **must** add this protection to your server block configuration manually.

Add this to your Nginx server block configuration:
```nginx
# Block access to tcms-data directory
location ~ ^/tcms-data/ {
    deny all;
    return 404;
}

# More comprehensive blocking (optional)
location ~ ^/tcms-data/.*\.(json|md|txt|log)$ {
    deny all;
    return 404;
}
```

#### Caddy

**Required for Caddy Users**: Caddy does not process `.htaccess` files. You **must** add this protection to your Caddyfile manually.

Add this to your Caddyfile:
```caddy
# Block access to tcms-data directory
@blocked path /tcms-data/*
respond @blocked 404

# Or use a more explicit approach
handle /tcms-data/* {
    respond 404
}
```

## Authentication and Session Security

### Strong Password Requirements

- Enforce minimum password length (8+ characters recommended)
- Require a mix of uppercase, lowercase, numbers, and special characters
- Implement password history to prevent reuse
- Consider implementing two-factor authentication (2FA)

### Session Security

Total CMS implements several session security measures:
- Session regeneration on login
- Secure session cookies (when using HTTPS)
- CSRF protection on all state-changing operations

### Where session files are stored

Total CMS keeps session files in `tcms-data/.system/sessions`, not PHP's default
location. PHP's default is a single directory shared by every site on the
server, and its cleanup runs on whichever site happens to trigger it, using
*that* site's session lifetime — often 24 minutes. A neighbouring site's cleanup
would delete your session files no matter what Total CMS configured, which is a
common cause of "it keeps logging me out" on shared hosting.

If that directory cannot be created or written to, Total CMS falls back to
PHP's default rather than failing: a session directory it cannot write to would
mean nobody can log in at all.

To put sessions somewhere else — a data directory on network storage is the
usual reason — set the path explicitly in `tcms.php`:

```php
$settings['session']['save_path'] = '/var/lib/tcms-sessions';
```

Any other PHP application on the site that shares the session cookie must
open its session in the same folder, or the two apps end up with separate
copies of one session. See [Sharing Sessions with Other PHP Apps](docs/operations/sharing-sessions).

### Sharing a login across subdomains

By default a login applies to one hostname. The session cookie is set for the
exact host, so signing in at `admin.example.com` does not sign you in at
`shop.example.com`, even when both are Total CMS installs.

Two things have to line up to share a login across subdomains of the same
domain:

1. **The installs must share their session storage**, which happens
   automatically when they share one `tcms-data` folder, or can be set
   explicitly with `session.save_path` on both.
2. **The cookie must be widened to the parent domain** on both installs:

   ```php
   $settings['session']['cookie_domain'] = '.example.com';
   ```

"Keep me signed in" follows the same rule — its cookie inherits the session
cookie's domain — so a persistent login is shared too.

If the installs share one `tcms-data` folder, also set:

```php
$settings['cache']['domainScoped'] = false;
```

so they share one cache namespace instead of keeping separate ones.

**Only do this if you control every subdomain.** Widening the cookie means
*every* host under `example.com` receives it on every request — including any
subdomain running software you did not write, and any subdomain someone else
controls. `HttpOnly` stops JavaScript from reading the cookie; it does nothing
about the server on the other end, which sees it in the request headers. A
forgotten `test.example.com` or a customer-controlled `blog.example.com` is
enough to turn this into a way to collect admin sessions.

This works for subdomains of one domain only. `example.com` and `example.net`
cannot share a cookie, and no configuration changes that — sharing a login
across genuinely different domains needs a redirect-based handoff, which Total
CMS does not currently provide.

### Account Security

- Limit login attempts to prevent brute force attacks
- Implement account lockout after failed attempts
- Log authentication events for monitoring
- Regularly review user accounts and remove inactive ones

## Content Security

### HTML Sanitization

Total CMS sanitizes HTML content by default to prevent XSS attacks. This is especially important for user-generated content.

**⚠️ Warning: Disabling HTML Sanitization**

While it's possible to disable HTML sanitization for certain fields, this significantly increases security risks:

```json
{
  "htmlclean" : false
}
```

**Risks of disabling sanitization:**
- **Cross-Site Scripting (XSS)**: Malicious scripts can steal user sessions, redirect users, or modify page content
- **HTML Injection**: Attackers can inject malicious HTML that breaks page layouts or functionality
- **Data Theft**: Scripts can access and transmit sensitive data to external servers
- **Phishing**: Malicious content can mimic legitimate forms to steal credentials

**If you must allow raw HTML:**
1. Only enable it for trusted administrator accounts
2. Never allow it for public-facing content
3. Implement Content Security Policy headers
4. Regularly audit content for suspicious code
5. Consider using a more restrictive whitelist approach

### SVG Security

SVG files can contain JavaScript and other potentially dangerous content. Total CMS automatically sanitizes SVG uploads to remove:

- `<script>` tags
- Event handlers (onclick, onload, etc.)
- External references
- JavaScript in URLs

```json
{
  "svgclean" : false
}
```

### Template Output Escaping (Twig)

**Twig autoescaping is OFF globally in Total CMS.** This is deliberate: content fields hold author-authored HTML and Markdown that must render as markup, not as escaped text (`{{ post.content }}`, `{{ body | markdown }}`, etc.). With autoescape on, every content field would render as visible HTML entities.

The trade-off is that **Twig does not escape output for you**. The write-path HTML sanitizer (above) scrubs rich-text content saved through the admin, which covers the primary content vector — but it does **not** protect everything a template can print:

- Output rendered by an **extension's own templates** (especially anything anonymous/agent-supplied)
- Free-form `page.data.*` values on Site Builder pages
- Query-string / request values echoed into a page
- Fields where `htmlclean` has been disabled

For any value that is not trusted author content, escape it explicitly:

```twig
{# Escape untrusted text #}
{{ user_supplied | e }}

{# Escape inside an HTML attribute #}
<a title="{{ user_supplied | e('html_attr') }}">…</a>

{# Or turn autoescaping on for a whole block #}
{% autoescape 'html' %}
    {{ user_supplied }}
{% endautoescape %}
```

This is the responsibility of template and **extension authors**: a handler that prints `{{ input }}` has no automatic XSS protection. When in doubt, escape.


## File Upload Security

### Best Practices

1. **File Type Restrictions**: Only allow necessary file types
2. **File Size Limits**: Set appropriate maximum file sizes
3. **Filename Sanitization**: Special characters are automatically removed
4. **MIME Type Verification**: Files are checked beyond just extensions
5. **Upload Directory**: Ensure upload directories are not executable

### Configuration

Configure upload restrictions in your collection schemas:

```json
{
	"rules" : {
		"size"     : {"min":0,"max":300},
		"filetype" : ["image/jpeg", "image/png", "application/pdf"],
		"filename" : ["image.jpg"]
	}
}
```


## Running Behind a Proxy

Rate limiting, webhook throttling and the OAuth registration log all need to
know which address a request came from. When Total CMS sits behind a reverse
proxy or a CDN, the connecting address is the proxy's, and the real visitor is
in a header the proxy adds — `CF-Connecting-IP` for Cloudflare, or
`X-Forwarded-For`.

The catch is that a visitor can send those headers too, and nothing about a
header says who set it. If Total CMS believed them unconditionally, anyone could
send a different value on each request, land in a fresh rate-limit bucket every
time, and never be limited at all.

`trustProxyHeaders` in `tcms.php` decides when those headers are believed:

```php
$settings['trustProxyHeaders'] = 'auto'; // 'auto' | 'always' | 'never'
```

| Value | Behaviour |
| --- | --- |
| `auto` (default) | Believe the headers only when the request arrives from a private or loopback address — a reverse proxy on the same machine or LAN. |
| `always` | Believe the headers on every request. |
| `never` | Ignore the headers; always use the connecting address. |

**`auto` is right for nginx or Apache in front of PHP**, which is the usual
setup, and it needs no configuration. It is also safe on a server exposed
directly to the internet, because a request arriving from a public address is
not coming from your proxy.

### Behind Cloudflare

**Nothing to configure.** Total CMS ships Cloudflare's published edge ranges and
recognises requests coming from them, so `auto` believes `CF-Connecting-IP` when
it genuinely came from Cloudflare and ignores it when someone else sends it. The
ranges are refreshed from
[cloudflare.com/ips](https://www.cloudflare.com/ips/) when each release is
built.

Settings → Server Info shows what your install is doing, including when the
bundled ranges were last verified. If Cloudflare ever adds a range that your
version predates, that panel says so directly rather than leaving you to work it
out from rate limits misbehaving — Cloudflare stamps every forwarded request
with a `CF-Ray` header, so a request carrying one from an address outside the
known ranges is a reliable sign the list needs updating. Updating Total CMS
fixes it.

Whatever the setting, restricting your origin so it can only be reached through
Cloudflare remains worth doing — see
[protecting your origin server](https://developers.cloudflare.com/fundamentals/security/protect-your-origin-server/).

### Behind another CDN or proxy

For a proxy that connects from a public address and is not Cloudflare — Fastly,
Akamai, a load balancer on another host — `auto` has no way to tell it from a
visitor, so it will ignore its headers. Two options:

1. **Resolve the address in your web server.** Apache's `mod_remoteip` and
   nginx's `real_ip` module rewrite the connecting address from a header, but
   only for requests that genuinely came from the proxy's IP ranges.
   `REMOTE_ADDR` then holds the real visitor before PHP sees it, `auto` is
   correct, and your access logs get the right address too.

   ```nginx
   set_real_ip_from 10.0.0.0/8;   # your proxy's addresses
   real_ip_header X-Forwarded-For;
   ```

2. **Set `trustProxyHeaders` to `always`.** Simpler, and appropriate when you
   cannot configure the web server. It believes the headers no matter who sent
   them, so it is only safe once your origin cannot be reached directly. If
   someone can reach your origin by IP, this setting is an open door.

## HTTPS and Transport Security

### Always Use HTTPS

1. Obtain an SSL certificate (Let's Encrypt provides free certificates)
2. Configure your web server to use HTTPS
3. Implement HTTP to HTTPS redirects
4. Enable HSTS (HTTP Strict Transport Security)

### Apache HTTPS Configuration
```apache
# Redirect HTTP to HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]

# Enable HSTS
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### Nginx HTTPS Configuration
```nginx
# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name example.com;
    return 301 https://$server_name$request_uri;
}

# HTTPS server block
server {
    listen 443 ssl http2;
    server_name example.com;

    # Enable HSTS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}
```

### Caddy HTTPS Configuration
```caddy
# Caddy automatically handles HTTPS, but you can be explicit
example.com {
    header Strict-Transport-Security "max-age=31536000; includeSubDomains"
}
```

## Additional Security Headers

Total CMS sets security headers on every admin, login, and setup page automatically — no server configuration needed:

| Header | Value |
|---|---|
| `Content-Security-Policy` | `frame-ancestors 'self'` (clickjacking protection) |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |

On **Apache**, the `.htaccess` that ships in `public/` sends the first three of those on your public pages too, including Site Builder pages, forms and assets: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`. That stops another site framing your contact or sign-up form. If another site needs to embed your pages, delete the `X-Frame-Options` line. HSTS is in the same file, commented out. Turn it on only once HTTPS works everywhere on the domain, because browsers remember it for a year.

A Content Security Policy for your public pages is your decision, not the CMS's, and so is anything on Nginx or Caddy, which don't read `.htaccess`. Set those at the server level:

### Apache
```apache
# Security headers
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set X-XSS-Protection "1; mode=block"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"
```

### Nginx
```nginx
# Security headers
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
```

### Caddy
```caddy
header {
    X-Content-Type-Options nosniff
    X-Frame-Options SAMEORIGIN
    X-XSS-Protection "1; mode=block"
    Referrer-Policy strict-origin-when-cross-origin
    Permissions-Policy "geolocation=(), microphone=(), camera=()"
}
```

## Checking Your Install

Total CMS can check your install for common security problems. It looks at your settings, your accounts, your templates and, for a few checks, how your web server answers real requests. It never changes anything. Each finding says what it saw and how to fix it, and links to the matching section below.

Findings have three severities:

| Severity | Meaning |
|---|---|
| Critical | Something private is likely open to anyone. Fix it now. |
| Warning | A real weakness. Fix it soon. |
| Info | Worth knowing. No action needed if it is on purpose. |

A check that could not run is shown as **not checked** with the reason. It is never shown as passing.

### Where to run it

- **Server Checker page.** Admin → Utilities → Server Checker has a Security section. It runs the checks live, using the real host name and HTTPS state of your request. Behind a proxy or CDN that ends HTTPS and forwards plain HTTP (Cloudflare Flexible, a load balancer, nginx in front of Apache), the HTTPS state comes from the `X-Forwarded-Proto` header, so the checks see the HTTPS site your visitors see.
- **`tcms check`.** The command line prints the same Security section. See [`tcms check`](docs/extensions/cli#check).
- **Dashboard.** Admins see a red alert on the dashboard when the last report had a critical finding. The last report is the one saved by the Server Checker page or `tcms check` in the past 24 hours, in `tcms-data/.system/security-report.json`, so the page and the command line share it. Your current ignore list is applied when the dashboard reads it. With no report from the past 24 hours, the dashboard runs only the checks that read settings and files.

The command line does not have a host name, so it uses the address the site last recorded when someone loaded a page. On a fresh install, or after a cache clear, checks that need that address are listed as not checked until someone visits the admin or you set `domain` in `config/tcms.php`. Checks that only read your settings and files still run.

### What changed for `tcms check`

- `tcms check` now exits non-zero on any critical security finding that is not ignored. Before, it only failed when the install could not run.
- `--no-security` skips the Security section and restores the old exit behavior.
- `--json` has a `security` key with `checked`, `findings` and `url`. `url` is `null` when the command line does not know the site's address.

```bash
tcms check                 # includes the Security section
tcms check --no-security   # the old behavior
tcms check --json
```

### Ignoring a finding

Some findings are on purpose. List their check ids in `config/tcms.php`:

```php
<?php
return [
	'security' => [
		'ignore' => ['xmlrpc-on', 'dev-mode-on'],
	],
];
```

An ignored finding still shows, marked ignored. It does not count toward the exit code of `tcms check` and does not raise the dashboard alert.

### How the web checks work

The data folder and sensitive file checks write a small test file with a random name and random content, fetch it over HTTP, and delete it. If the web server returns that exact content, the folder is public. A server that answers every address with a page never causes a false alarm.

The test file is made readable to everyone (mode 0644) before it is fetched, so a strict umask, or a command line that runs as a different user than the web server, can't make a public folder look blocked.

A redirect is not an answer. If the site redirects a test request, for example from `http://` to `https://` or to a login page, the check is listed as not checked and names where the redirect pointed. It is never counted as blocked.

If the site can't reach itself (a timeout or a refused connection), the first failed request ends the web checks for that run. The rest are listed as not checked straight away instead of each waiting for its own timeout.

A folder counts as inside the public web folder when its configured path is inside it, or when the real path it resolves to (with symlinks followed) is inside it. So a data folder configured through a symlink that points into the web folder is checked, and so is a web folder that is itself a symlink. One layout is not caught: a data folder configured by its real path outside the web folder, with a separate symlink inside the web folder pointing to it. Total CMS doesn't search the web folder for symlinks, so that folder is reported as outside. Remove such a symlink, or set `datadir` to the symlink path so the check tests it.

The checks only make requests to your own site. Titles and fixes are in English in every admin language.

### Admin accounts

**Check id:** `admin-accounts`

Counts the accounts in the `admin` group and how many have a passkey. More than five administrators is a warning. Otherwise it is info.

Every administrator can change anything, including schemas, API keys and extensions. Each extra account is another password that can be stolen.

**Fix:** Give day-to-day users an access group with only the permissions they need. Keep `admin` for a few people. Turn on passkeys for administrators.

### API keys broad

**Check id:** `api-keys-broad`

Looks for API keys that allow write methods (`POST`, `PUT`, `PATCH`, `DELETE`) on every path, and keys that were never used and created more than 30 days ago, or not used for 180 days. Info.

A leaked key with write access to everything can change or delete all your content.

**Fix:** Limit each key to the methods and paths its job needs. Delete keys you no longer use.

### Data folder public

**Check id:** `data-folder-public`

Writes a test file into the data folder (`tcms-data`) when it is inside the public web folder, and tries to download it.

- **Critical:** the test file could be downloaded. Anyone can read your content, users and settings.
- **Info on Apache and LiteSpeed:** the folder is inside the web folder but blocked. This is normal for zip and Stacks installs, which are protected by the `.htaccess` file that ships in the folder.
- **Warning on other servers:** the folder is inside the web folder and blocked, but only by a server rule. If that rule stops working, the folder becomes public.

**Fix:** Move the data folder above the public web folder and update `datadir` in `config/tcms.php`. If it must stay, block it:

On Apache, keep the `.htaccess` file in the folder and make sure `AllowOverride` is enabled for your site.

On Nginx, `.htaccess` is ignored. Add this and reload:

```nginx
location ^~ /tcms-data/ {
	deny all;
	return 404;
}
```

### Debug on

**Check id:** `debug-on`

Warns when the `debug` setting is on for a live site. Error pages can show file paths and code to visitors. A local site (`localhost`, a bare IP address, or a host ending in `.localhost` or `.test`) is fine. When the command line doesn't know the site's address yet, this is listed as not checked.

**Fix:** Set `debug` to `false` in `config/tcms.php`.

### Dev mode on

**Check id:** `dev-mode-on`

Reports when dev mode is active on a live site. Dev mode turns caching off and expires on its own. Info. Local sites and an unknown address are treated as in [Debug on](#debug-on).

**Fix:** Turn dev mode off in the admin when you finish testing.

### Directory listing

**Check id:** `directory-listing`

Requests your assets folder and looks for a file list ("Index of"). A listing shows visitors every file in the folder. Warning. A redirected request is listed as not checked.

**Fix:** On Apache, add `Options -Indexes` to your `.htaccess` file. On Nginx, set `autoindex off;` in your server block and reload.

### MCP anonymous

**Check id:** `mcp-anonymous`

When `mcp.publicAccess` is on, lists the collections that anonymous AI agents can read.

- **Warning:** a public collection has an email or password field that MCP returns.
- **Info:** collections are public over MCP and have no such fields.

**Fix:** Set the collection's MCP access to something other than public, or mark the sensitive properties as not exposed to MCP. To close anonymous access, set `mcp.publicAccess` to `false`.

### No HTTPS

**Check id:** `no-https`

Warns when a live site is served over plain HTTP. Logins and form data travel unencrypted. A site behind a proxy that sends `X-Forwarded-Proto: https` counts as HTTPS.

**Fix:** Install a certificate (most hosts offer a free one) and redirect HTTP to HTTPS. See [HTTPS and Transport Security](#https-and-transport-security).

### Raw request data

**Check id:** `raw-request-data`

Scans your Site Builder templates (not the built-in ones) for `getData`, `postData` or `sessionData` on the same line as `|raw`. Visitors control that data, so printing it unescaped lets them inject scripts into your page. Warning. Each finding names the file and line.

This is a line-by-line pattern match, not a full analysis. It misses an expression split across lines and request data assigned to a variable first.

**Fix:** Remove `|raw` so Twig escapes the value, or sanitize it first.

### Registration group

**Check id:** `registration-group`

Looks at collections that allow public registration and the access groups new members join.

- **Critical:** new members join `admin`, or a group that can do any of these: open any admin utility (access groups, API keys and the rest are utilities), create or change schemas, edit Site Builder templates, or create, change or delete collection settings (which would let a member open registration elsewhere or change its groups). The finding names what the group can do.
- **Warning:** new members join a group that can create, update or delete collection content.
- **Info:** registration gives new members no group.

New members are logged in right away, so anyone, including bots, can sign up.

**Fix:** Give the registration group read access only. Protect the form with a CAPTCHA, a rate limit or email verification. See [Access Groups](docs/auth/access-groups#users-without-a-group) for the no-group case.

### Security headers

**Check id:** `security-headers`

Requests the home page and looks for `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy`. On HTTPS it also looks for `Strict-Transport-Security` (HSTS), reported separately as `security-headers-hsts`. Info.

**Fix:** The `.htaccess` that ships in `public/` sets the first three on Apache. On Nginx add them to your server block and reload:

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

HSTS makes browsers refuse plain HTTP for the full `max-age`. Make sure every page and subdomain works over HTTPS first, and start short:

```apache
Header always set Strict-Transport-Security "max-age=300"
```

```nginx
add_header Strict-Transport-Security "max-age=300" always;
```

### Sensitive files public

**Check id:** `sensitive-files-public`

Tests the logs, cache and tmp folders with a test file, and requests project files, but only those that sit inside the public web folder:

- **Critical:** a logs, cache or tmp folder is downloadable, or `.git/config` or `.env` is readable.
- **Warning:** `composer.lock` or `vendor/composer/installed.json` is readable. They list every package and version you run.

These files can hold passwords, tokens and source code.

**Fix:** Move the folder or file above the public web folder, or block it. On Apache, add a `.htaccess` file containing `Require all denied` to the logs, cache or tmp folder (no `.htaccess` ships in these), add a `<Files>` deny rule for a file, and make sure `AllowOverride` is enabled. On Nginx, add a rule for each path and reload:

```nginx
location ^~ /logs/ {
	deny all;
	return 404;
}
location = /.env {
	deny all;
	return 404;
}
```

### Version behind

**Check id:** `version-behind`

Asks the update checker whether a newer Total CMS version exists. Warning. Updates carry security fixes.

**Fix:** Update from the admin, or run `tcms update:apply`. Composer installs update with `composer update`.

### XMLRPC on

**Check id:** `xmlrpc-on`

Reports when the WordPress-compatible XML-RPC endpoint is on (`xmlrpc.enable`). It accepts API key logins from the internet. Info.

**Fix:** If you do not publish from MarsEdit, Byword or Ulysses, set `xmlrpc.enable` to `false` in `config/tcms.php`.

## Error Monitoring

Total CMS can report application errors to the developer so bugs get discovered and fixed quickly — often before you notice anything went wrong, and without you having to file a report. You choose during the setup wizard, and you can change your mind at any time in **Settings → General** ("Share Application Errors with Developer").

**What a report contains:** the exception, its stack trace, the request URL and method, and the Total CMS version.

**What is never sent:** the reporting client is explicitly configured to never attach request bodies, cookies, session data, IP addresses, or user information. Your content, credentials, and CMS data stay on your server.

**Air-gapped deployments:** installing an [offline license](docs/operations/licenses#offline-licensing) disables error monitoring automatically at the configuration layer — a network-isolated install never attempts an outbound call, regardless of the settings toggle.

When disabled, errors are still logged locally on your server — see the log files in your data directory.

## Regular Security Maintenance

### Keep Software Updated

1. **Total CMS**: Regularly check for and install updates
2. **PHP**: Keep PHP version current (8.2+ recommended)
3. **Web Server**: Update Apache/Nginx/Caddy regularly
4. **Dependencies**: Run `composer update` regularly (test first!)

### Security Monitoring

1. **Access Logs**: Regularly review web server access logs
2. **Error Logs**: Monitor PHP and application error logs
3. **Failed Logins**: Track and investigate failed login attempts
4. **File Changes**: Monitor for unexpected file modifications

### Backup Strategy

1. **Regular Backups**: Automate daily backups of tcms-data
2. **Offsite Storage**: Store backups in a separate location
3. **Test Restores**: Regularly verify backup integrity
4. **Version Control**: Consider using Git for configuration files

### Security Checklist

- [ ] tcms-data folder is protected or moved outside document root
- [ ] HTTPS is enabled with valid certificate
- [ ] Strong passwords are enforced
- [ ] File upload restrictions are configured
- [ ] Security headers are properly set
- [ ] Regular backups are configured
- [ ] Software is kept up to date
- [ ] Access logs are monitored
- [ ] HTML sanitization is enabled (unless explicitly required otherwise)

## Emergency Response

If you suspect a security breach:

1. **Immediate Actions**:
   - Change all passwords
   - Review access logs
   - Check for unauthorized file changes
   - Clear the cache: `/api/emergency/cache/clear`

2. **Investigation**:
   - Review user accounts for unauthorized access
   - Check for suspicious files in upload directories
   - Examine database/JSON files for injected content

3. **Recovery**:
   - Restore from clean backup if necessary
   - Update all software
   - Implement additional security measures
   - Document the incident for future reference

## Additional Resources

- [OWASP Security Guidelines](https://owasp.org/)
- [PHP Security Best Practices](https://www.php.net/manual/en/security.php)
- [Web Server Security Guides](https://www.nist.gov/cyberframework)

Remember: Security is an ongoing process, not a one-time setup. Regular reviews and updates are essential for maintaining a secure Total CMS installation.