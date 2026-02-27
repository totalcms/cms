---
title: "Total CMS Security Guide"
description: "Secure your Total CMS installation with tcms-data folder protection, HTTPS setup, HTML and SVG sanitization, upload restrictions, and security headers."
---

# Total CMS Security Guide

This guide provides essential security recommendations for protecting your Total CMS installation and data.

## Table of Contents
- [Total CMS Security Guide](#total-cms-security-guide)
	- [Table of Contents](#table-of-contents)
	- [Protecting the tcms-data Folder](#protecting-the-tcms-data-folder)
		- [Best Practice: Move Outside Document Root](#best-practice-move-outside-document-root)
		- [Alternative: Restrict Access Within Document Root](#alternative-restrict-access-within-document-root)
			- [Apache (.htaccess)](#apache-htaccess)
			- [Nginx](#nginx)
			- [Caddy](#caddy)
	- [Authentication and Session Security](#authentication-and-session-security)
		- [Strong Password Requirements](#strong-password-requirements)
		- [Session Security](#session-security)
		- [Account Security](#account-security)
	- [Content Security](#content-security)
		- [HTML Sanitization](#html-sanitization)
		- [SVG Security](#svg-security)
	- [File Upload Security](#file-upload-security)
		- [Best Practices](#best-practices)
		- [Configuration](#configuration)
	- [HTTPS and Transport Security](#https-and-transport-security)
		- [Always Use HTTPS](#always-use-https)
		- [Apache HTTPS Configuration](#apache-https-configuration)
		- [Nginx HTTPS Configuration](#nginx-https-configuration)
		- [Caddy HTTPS Configuration](#caddy-https-configuration)
	- [Additional Security Headers](#additional-security-headers)
		- [Apache](#apache)
		- [Nginx](#nginx-1)
		- [Caddy](#caddy-1)
	- [Regular Security Maintenance](#regular-security-maintenance)
		- [Keep Software Updated](#keep-software-updated)
		- [Security Monitoring](#security-monitoring)
		- [Backup Strategy](#backup-strategy)
		- [Security Checklist](#security-checklist)
	- [Emergency Response](#emergency-response)
	- [Additional Resources](#additional-resources)

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
		"filename" : ["image.jpg"],
	}
}
```


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

Total CMS automatically sets several security headers, but you can enhance them:

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
   - Clear the cache: `/emergency/cache/clear`

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