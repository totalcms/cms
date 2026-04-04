---
title: "Nginx Configuration"
description: "Configure Nginx to work with Total CMS including URL rewriting, PHP-FPM setup, and security rules for protecting the tcms-data directory."
---

# Nginx Configuration

Total CMS ships with `.htaccess` files for Apache. If you are running Nginx, you will need to add equivalent configuration to your Nginx server block. Nginx does not read `.htaccess` files.

## How Total CMS Routing Works

Total CMS uses two layers of URL rewriting:

1. **Root rewrite** — All requests to the `tcms/` directory are routed into the `public/` subdirectory
2. **Front controller** — Any request that does not match a real file or directory inside `public/` is handled by `index.php`
3. **Data protection** — The `tcms-data/` directory must be blocked from direct access

## Standalone Installation

If Total CMS is installed at the document root (or you can set the Nginx `root` to the `public/` directory directly):

```nginx
server {
	listen 80;
	server_name example.com;
	root /var/www/totalcms/public;
	index index.php;

	# Block direct access to tcms-data
	location ~* /tcms-data/ {
		deny all;
		return 403;
	}

	# Front controller
	location / {
		try_files $uri $uri/ /index.php?$query_string;
	}

	# PHP-FPM
	location ~ \.php$ {
		fastcgi_pass unix:/var/run/php/php-fpm.sock;
		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
		include fastcgi_params;
	}

	# Deny access to dotfiles (.htaccess, .env, etc.)
	location ~ /\. {
		deny all;
	}
}
```

## Subdirectory Installation (RapidWeaver / Stacks)

When Total CMS is installed inside a subdirectory such as `/rw_common/plugins/stacks/tcms/`, the configuration needs to account for the path prefix:

```nginx
# Block direct access to tcms-data
location ~* /tcms-data/ {
	deny all;
	return 403;
}

# Route requests into the public/ subdirectory and use front controller
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

Adjust the path to match your actual installation directory.

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

On Apache, Total CMS can set session lifetime via `.htaccess`. On Nginx, configure this in your PHP-FPM pool (`www.conf`) or `php.ini` instead:

```ini
; Set session timeout to 24 hours (matches Total CMS default)
php_admin_value[session.gc_maxlifetime] = 86400
```

## Verifying Your Configuration

After updating your Nginx configuration:

1. Test the configuration: `nginx -t`
2. Reload Nginx: `systemctl reload nginx`
3. Navigate to your Total CMS admin URL (e.g., `example.com/tcms/admin`)
4. If you see a 404, double-check the `try_files` directive and ensure it falls back to `index.php`
5. If you see a 502, verify your PHP-FPM socket path is correct and that PHP-FPM is running
