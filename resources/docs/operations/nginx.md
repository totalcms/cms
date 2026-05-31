---
title: "Nginx Configuration"
description: "Configure Nginx to work with Total CMS — front-controller routing, blocking the tcms-data directory, PHP-FPM, and snippets for the recommended docroot layout."
related:
  - operations/apache
  - operations/security
  - operations/deployment
---

# Nginx Configuration

Nginx does not read `.htaccess` files, so the rewrite rules that ship inside `public/.htaccess` and `tcms-data/.htaccess` have to be expressed in your server block instead. This page covers the equivalents — front-controller routing for `public/index.php`, an explicit deny rule for `tcms-data/`, and PHP-FPM hand-off.

If you missed the snippets on the [Setup Wizard's Server Configuration step](#the-setup-wizard-snippet), the configuration below is the same content the wizard generates.

## How Total CMS Routing Works on Nginx

Two things have to happen for a Total CMS site to serve correctly:

1. **Front-controller routing** — Any URL that doesn't match a real file or directory under `public/` is passed to `public/index.php`. That's how `/admin`, `/api/...`, `/sitemap`, and your Site Builder pages all reach the framework.
2. **Data protection** — `tcms-data/` is the flat-file store for collections, uploads, secrets, and runtime state. It must never be served directly. The simplest defense is to keep `tcms-data/` outside your docroot. If it has to live under the docroot, deny it explicitly.

The two snippets below cover both concerns.

## The Setup Wizard Snippet

The Setup Wizard's **Server Configuration** step (`/setup/server-config` on a fresh install) inspects your environment and generates the exact rules you need, including the URL prefix when `public/` lives below your docroot. If you skipped the wizard, the snippets in this guide are the canonical content.

## Recommended Layout — `public/` as the Docroot

Point Nginx's `root` directive at `public/`. This is the cleanest setup: no path-stripping, no docroot bridging, and `tcms-data/` is naturally outside the docroot.

```nginx
server {
	listen 80;
	server_name example.com;
	root /var/www/totalcms/public;
	index index.php;

	# Front-controller routing — pass anything that isn't a real file
	# or directory to public/index.php.
	location / {
		try_files $uri $uri/ /index.php?$query_string;
	}

	# PHP-FPM hand-off
	location ~ \.php$ {
		fastcgi_pass unix:/var/run/php/php-fpm.sock;
		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		include fastcgi_params;
	}

	# Deny dotfiles (.env, .git, .htaccess, etc.)
	location ~ /\. {
		deny all;
	}
}
```

When `public/` is the docroot, you do not need a `location ~* /tcms-data/` block — the directory sits one level above the docroot and is unreachable by URL.

## When `tcms-data/` Lives Under the Docroot

Some hosting setups can't move `tcms-data/` outside the served tree. In that case add an explicit deny rule for it:

```nginx
# Block direct access to tcms-data
location ~* /tcms-data/ {
	deny all;
	return 403;
}
```

Place this **before** the `location /` block so it matches first.

## Subdirectory Install — Docroot Above `public/`

If you can't change the docroot — for example, when Total CMS is mounted inside an existing site — Nginx needs to route requests through `public/index.php` while exposing them at a URL prefix. The pattern:

```nginx
server {
	listen 80;
	server_name example.com;
	root /var/www/example.com;
	index index.php;

	# Block direct access to tcms-data
	location ~* /tcms-data/ {
		deny all;
		return 403;
	}

	# Total CMS mounted at /cms
	location /cms/ {
		try_files $uri $uri/ /cms/public/$uri /cms/public/index.php?$query_string;
	}

	# PHP-FPM for Total CMS PHP scripts
	location ~ /cms/.*\.php$ {
		fastcgi_pass unix:/var/run/php/php-fpm.sock;
		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		include fastcgi_params;
	}
}
```

Adjust `/cms/` to match the URL prefix where your install lives.

## Stacks Plugin Install

When Total CMS is installed as a Stacks plugin at `/rw_common/plugins/stacks/tcms/`, the configuration follows the subdirectory pattern with that path:

```nginx
# Block direct access to tcms-data
location ~* /tcms-data/ {
	deny all;
	return 403;
}

# Route requests into the public/ subdirectory and use the front controller
location /rw_common/plugins/stacks/tcms/ {
	try_files $uri $uri/ /rw_common/plugins/stacks/tcms/public/$uri /rw_common/plugins/stacks/tcms/public/index.php?$query_string;
}

# PHP-FPM for Total CMS
location ~ /rw_common/plugins/stacks/tcms/.*\.php$ {
	fastcgi_pass unix:/var/run/php/php-fpm.sock;
	fastcgi_index index.php;
	fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
	include fastcgi_params;
}
```

## PHP-FPM Socket

The `fastcgi_pass` directive must point to your PHP-FPM socket or TCP address. Common values:

| Distribution | Socket Path |
|---|---|
| Ubuntu/Debian | `unix:/var/run/php/php8.2-fpm.sock` |
| CentOS/RHEL | `unix:/var/run/php-fpm/www.sock` |
| macOS (Homebrew) | `unix:/opt/homebrew/var/run/php-fpm.sock` |
| TCP (any) | `127.0.0.1:9000` |

Check your PHP-FPM pool configuration (`www.conf`) for the exact `listen` value.

## Session Configuration

On Apache with `mod_php`, Total CMS can set session lifetime via `.htaccess`. On Nginx + PHP-FPM (the same is true for any PHP-FPM install, including Apache), `php_value` directives in `.htaccess` are ignored. Configure session lifetime in your PHP-FPM pool (`www.conf`) or `php.ini` instead:

```ini
; Set session timeout to 24 hours (matches Total CMS default)
php_admin_value[session.gc_maxlifetime] = 86400
```

Total CMS's "Keep me logged in" persistent sessions are handled separately and aren't affected by this setting.

## Optional Hardening & Performance

### HTTPS Redirect

Redirect HTTP to HTTPS at the server-block level rather than inside `location /`:

```nginx
server {
	listen 80;
	server_name example.com;
	return 301 https://$host$request_uri;
}
```

### Security Headers

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
# Only enable HSTS once HTTPS is fully working.
# add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

Total CMS sets a Content Security Policy via middleware — don't add a `Content-Security-Policy` header here unless you intend to override it.

### Compression

Enable gzip globally (typically in `nginx.conf`) or per server block:

```nginx
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml;
```

### Static Asset Caching

Far-future caching for hashed Vite output:

```nginx
location ~* \.([0-9a-f]{8,})\.(js|css|woff2)$ {
	expires 1y;
	add_header Cache-Control "public, immutable";
	try_files $uri =404;
}
```

### Rate limiting the MCP endpoint

If you rate-limit at the Nginx layer, give the MCP endpoint (`/mcp`, or `/cms/mcp`, `/tcms/mcp`, etc. on a subpath install) its **own** zone. AI agents batch tool calls in parallel — a single page-build from Cursor or Claude can fire a dozen requests within a second. Sharing the strict zone you use for admin login (commonly `1r/s`) will throttle those calls and surface as **timeouts**, not clean errors.

Define a dedicated zone in the `http {}` block (in `nginx.conf`):

```nginx
# Admin login — strict, brute-force resistant.
limit_req_zone $binary_remote_addr zone=tcms_login:10m rate=1r/s;

# MCP — agents batch calls, so allow a higher sustained rate + burst.
limit_req_zone $binary_remote_addr zone=tcms_mcp:10m rate=10r/s;
```

Apply each in its own `location` inside the server block, before the generic `location /`:

```nginx
location = /mcp {
	limit_req zone=tcms_mcp burst=30 nodelay;
	try_files $uri /index.php$is_args$args;
}

location = /admin/login {
	limit_req zone=tcms_login burst=5 nodelay;
	try_files $uri /index.php$is_args$args;
}
```

`burst` absorbs the parallel spike; `nodelay` serves the burst immediately rather than queuing it (queuing also reads as latency to the agent). Tune `rate`/`burst` up if you run many concurrent agents. Total CMS also applies its own per-IP throttle for anonymous MCP callers (`mcp.publicIpPerMinute`) — the Nginx zone sits in front of it; size the Nginx limit at or above the application limit so legitimate traffic isn't cut off before T3's own 429 logic runs. See [MCP Server → Rate limiting](docs/mcp/server) for the application-layer side.

## Verifying Your Configuration

After editing the server block:

1. Test the configuration: `nginx -t`
2. Reload Nginx: `systemctl reload nginx`
3. Navigate to your Total CMS admin URL. If you see a 404, double-check the `try_files` directive — it must fall back to `index.php`.
4. Request a route that doesn't map to a file (`/sitemap`, `/admin/login`). If Nginx 404s instead of Total CMS, the front-controller rule isn't firing.
5. Try to fetch a `tcms-data/` file directly. You should get a 403 (or a 404 if the directory sits outside the docroot). If you can read the file, the deny rule isn't being applied — check that the block sits *before* `location /`.
6. If you see a 502 Bad Gateway, verify your PHP-FPM socket path is correct and that PHP-FPM is running.
