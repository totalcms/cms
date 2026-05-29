---
title: "Apache & .htaccess Configuration"
description: "Apache and LiteSpeed rewrite rules for Total CMS — what ships in the box, where each .htaccess file lives, and common customizations like HTTPS redirects, caching, and compression."
related:
  - operations/nginx
  - operations/security
  - operations/deployment
---

# Apache & .htaccess Configuration

Total CMS routes every request that does not match a real file or directory through its front controller (`public/index.php`). On Apache, that routing is expressed as `mod_rewrite` rules in `.htaccess` files. LiteSpeed reads `.htaccess` the same way Apache does — this page applies to both.

If you missed the rewrite snippets on the [Setup Wizard's Server Configuration step](#the-setup-wizard-snippet), the rules below are the same content the wizard generates. The fastest way to view the exact snippet for *your* install — including the right URL prefix for sub-directory layouts — is to revisit the wizard, but for a fresh standalone install the standard rules in this guide will do.

## What Ships in the Box

A Composer install (`composer create-project totalcms/totalcms`) drops these `.htaccess` files into your project automatically:

| File | Purpose |
|------|---------|
| `public/.htaccess` | Front-controller rewrite — routes every non-file/non-directory request through `index.php`. |
| `tcms-data/.htaccess` | Hard-denies direct access to the data directory so collections, uploads, and secrets are never served by the web server. |
| `.htaccess` (project root) | **Only present when `public/` is NOT your docroot.** Redirects `/` into `public/` so subpath installs work without changing the docroot. |

Zip installs and the Stacks plugin layout ship the same files. If you are installing from a custom source and don't see them, the snippets below are the canonical content.

> **You only need the project-root `.htaccess` when your docroot is one level above `public/`.** If you point Apache's `DocumentRoot` directly at `public/` (the recommended layout — see [Filesystem](operations/filesystem)), only the two files inside `public/` and `tcms-data/` matter.

## The Setup Wizard Snippet

The Setup Wizard's **Server Configuration** step inspects your environment and generates the exact rewrite rules you need, including:

- The right URL prefix when `public/` lives below your docroot.
- An explicit deny rule for `tcms-data/` when it sits inside your docroot.

If you skipped the wizard or want to revisit it, the path is `/setup/server-config` on a fresh install. After installation, the same rules are documented below.

## Standalone Install — `public/` as the Docroot

This is the recommended layout: point Apache's `DocumentRoot` at `public/` and only the front-controller rule is needed.

`public/.htaccess`:

```apacheconf
# Redirect to front controller
RewriteEngine On
# RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

`tcms-data/.htaccess`:

```apacheconf
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

The `mod_authz_core` block is the Apache 2.4+ syntax; the fallback covers older Apache 2.2 hosts.

## Subdirectory Install — Docroot Above `public/`

If your docroot is the project root (so URLs look like `https://example.com/index.php` but the front controller is actually at `public/index.php`), add this `.htaccess` at the **project root** to bridge them:

```apacheconf
RewriteEngine On

# Don't rewrite if "public/" is already in the path
RewriteCond %{REQUEST_URI} !public/
RewriteRule ^$ public/ [L]

RewriteCond %{REQUEST_URI} !public/
RewriteRule ^(.*)$ public/$1 [L]
```

`public/.htaccess` is still required — the project-root file rewrites the URL *into* `public/`, then the rules in `public/.htaccess` take over and route the request through `index.php`.

## Stacks Plugin Install

When Total CMS is installed as a Stacks plugin at `/rw_common/plugins/stacks/tcms/`, the document root sits well above `public/`. Add the following to your docroot `.htaccess` to bridge the gap:

```apacheconf
RewriteEngine On

# Redirect all other requests to /rw_common/plugins/stacks/tcms/public
RewriteCond %{REQUEST_URI} ^/rw_common/plugins/stacks/tcms/
RewriteCond %{REQUEST_URI} !^/rw_common/plugins/stacks/tcms/public/
RewriteRule ^(.*)$ rw_common/plugins/stacks/tcms/public/$1 [L]

# Process requests through index.php if not a file or directory
RewriteCond %{REQUEST_URI} ^/rw_common/plugins/stacks/tcms/public/
RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-f
RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-d
RewriteRule ^rw_common/plugins/stacks/tcms/public/.*$ rw_common/plugins/stacks/tcms/public/index.php [QSA,L]
```

Adjust the install path if you mounted the plugin somewhere other than `/rw_common/plugins/stacks/tcms/`.

## Common Customizations

The snippets below are optional. Add them to `public/.htaccess` (or your docroot `.htaccess`) to harden, accelerate, or shape your install. Each block is independent — pick the ones you need.

### Force HTTPS

Redirect every request to HTTPS. Run after `RewriteEngine On`, before the front-controller rule:

```apacheconf
# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

If you are behind a proxy or load balancer that terminates TLS, swap the condition for the forwarded-protocol header:

```apacheconf
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Force `www` (or strip it)

Canonicalize the host name. Force `www`:

```apacheconf
RewriteCond %{HTTP_HOST} ^example\.com [NC]
RewriteRule ^(.*)$ https://www.example.com/$1 [L,R=301]
```

Strip `www`:

```apacheconf
RewriteCond %{HTTP_HOST} ^www\.example\.com [NC]
RewriteRule ^(.*)$ https://example.com/$1 [L,R=301]
```

### Security Headers

Defense-in-depth headers on every response:

```apacheconf
<IfModule mod_headers.c>
	Header set X-Content-Type-Options "nosniff"
	Header set X-Frame-Options "SAMEORIGIN"
	Header set Referrer-Policy "strict-origin-when-cross-origin"
	Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"
	# Only enable HSTS once you're confident HTTPS is fully working.
	# Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>
```

Total CMS already sets a Content Security Policy via middleware — don't add a `Content-Security-Policy` header here unless you intend to override it.

### Compression

Compress text-based responses with `mod_deflate`:

```apacheconf
<IfModule mod_deflate.c>
	AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript
	AddOutputFilterByType DEFLATE application/javascript application/json application/xml
	AddOutputFilterByType DEFLATE image/svg+xml
</IfModule>
```

### Static Asset Caching

Far-future cache headers on hashed assets:

```apacheconf
<IfModule mod_expires.c>
	ExpiresActive On
	ExpiresByType image/jpeg "access plus 1 year"
	ExpiresByType image/png "access plus 1 year"
	ExpiresByType image/webp "access plus 1 year"
	ExpiresByType image/svg+xml "access plus 1 month"
	ExpiresByType text/css "access plus 1 month"
	ExpiresByType application/javascript "access plus 1 month"
	ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
```

Vite-built assets carry content hashes in their filenames, so they can safely use immutable caching:

```apacheconf
<IfModule mod_headers.c>
	<FilesMatch "\.([0-9a-f]{8,})\.(js|css|woff2)$">
		Header set Cache-Control "public, max-age=31536000, immutable"
	</FilesMatch>
</IfModule>
```

### Block Hidden Files

Belt-and-braces protection for any other dotfiles in your tree (`.env`, `.git`, etc.):

```apacheconf
<FilesMatch "^\.">
	Order Allow,Deny
	Deny from all
</FilesMatch>
```

Note that `tcms-data/.htaccess` already blocks `tcms-data/` entirely. This rule covers stray dotfiles elsewhere.

### Disable Directory Listings

If you ever serve a directory that has no `index.php` or `index.html`, this prevents Apache from listing its contents:

```apacheconf
Options -Indexes
ServerSignature Off
```

## PHP Settings via `.htaccess`

The `public/.htaccess` shipped with Total CMS contains a commented hint for session lifetime:

```apacheconf
# Session Configuration
# Set session timeout to 24 hours (86400 seconds) for non-persistent logins
# php_value session.gc_maxlifetime 86400
```

Uncomment it if your host runs PHP as an Apache module (`mod_php`). It will have **no effect** under PHP-FPM or PHP-CGI — those configurations ignore `php_value` directives in `.htaccess`. For PHP-FPM, set the value in your pool's `www.conf` instead:

```ini
php_admin_value[session.gc_maxlifetime] = 86400
```

Total CMS's "Keep me logged in" persistent sessions are handled separately from `session.gc_maxlifetime` and aren't affected by this setting.

## Required Apache Modules

The rewrite rules need `mod_rewrite` enabled. The header and caching customizations rely on additional modules:

| Module | Used by |
|--------|---------|
| `mod_rewrite` | Front-controller routing (required) |
| `mod_headers` | Security headers, immutable asset caching |
| `mod_deflate` | Response compression |
| `mod_expires` | Far-future cache headers |
| `mod_authz_core` | Modern `Require all denied` syntax in `tcms-data/.htaccess` |

On Debian/Ubuntu, enable them with `a2enmod rewrite headers deflate expires` and restart Apache.

The vhost (or virtual host configuration) for the site must also include `AllowOverride All` (or at minimum `AllowOverride FileInfo Limit AuthConfig`) for the `.htaccess` files to take effect:

```apacheconf
<Directory /var/www/totalcms/public>
	AllowOverride All
	Require all granted
</Directory>
```

If `.htaccess` rules appear to be ignored, this is the first thing to check.

## Verifying Your Configuration

After installing or editing `.htaccess` files:

1. Reload Apache: `systemctl reload apache2` (Debian/Ubuntu) or `apachectl graceful` (other distros).
2. Visit your Total CMS admin URL — you should land on the login page or the wizard.
3. Request a route that doesn't map to a file, e.g. `/sitemap`. If you get a 404 from Apache (not Total CMS), the rewrite rule isn't firing — check `AllowOverride` in the vhost and that `mod_rewrite` is loaded.
4. Try to fetch a `tcms-data/` file directly (e.g. `https://example.com/tcms-data/collections/index.json`). Apache should respond with 403. If you see the file content, the `tcms-data/.htaccess` rule isn't being applied — again, check `AllowOverride`.

## Troubleshooting

**500 Internal Server Error after editing `.htaccess`.** Apache rejects malformed `.htaccess` files with a 500. Check `error.log` — the failing line number is in the message. Common culprits: a stray `<IfModule>` without a matching `</IfModule>`, or a `Header` directive when `mod_headers` isn't loaded.

**Rules appear to be ignored.** `AllowOverride None` in the vhost disables `.htaccess` processing site-wide. Set it to `All` for the directory containing your Total CMS files.

**404 on `/admin` or other Total CMS URLs.** The front-controller rewrite isn't firing. Confirm `mod_rewrite` is loaded (`apachectl -M | grep rewrite`) and that the `.htaccess` files are present and readable.

**Static assets return 404.** The "pass through real files" guard (`RewriteCond %{REQUEST_FILENAME} -f`) should let real files through untouched. If it isn't, check that the request path matches a real file relative to the directory containing the `.htaccess` — symlinks across docroots can break the check.
