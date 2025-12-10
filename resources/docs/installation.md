# Installation & System Requirements

This guide covers the system requirements and installation process for Total CMS.

## System Requirements

### PHP Requirements

- **PHP 8.2 or higher** (PHP 8.3 and 8.4 supported)
- Required PHP extensions:
  - `json` - JSON parsing
  - `mbstring` - Multibyte string handling
  - `gd` or `imagick` - Image processing
  - `fileinfo` - File type detection
  - `curl` - HTTP requests (for license validation, embeds)
  - `zip` - JumpStart import/export

### Recommended PHP Extensions

These extensions enhance performance and enable additional features:

- `apcu` - High-performance caching (recommended for production)
- `redis` - Redis caching support
- `memcached` - Memcached caching support
- `opcache` - PHP bytecode caching
- `exif` - Image metadata extraction

### Web Server

Total CMS works with any PHP-compatible web server:

- **Apache 2.4+** with `mod_rewrite` enabled
- **Nginx** with proper PHP-FPM configuration
- **LiteSpeed** or other compatible servers

### File System

- Write access to the `tcms-data` directory
- Recommended: 100MB+ free disk space (varies by content volume)

### Browser Support (Admin Dashboard)

The admin dashboard supports modern browsers:
- Chrome/Edge (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)

## Installation

### Step 1: Upload Files

Upload the Total CMS files to your web server. The directory structure should look like:

```
your-site/
├── config/
├── public/
├── resources/
├── src/
├── vendor/
├── tcms-data/          (created automatically)
├── .htaccess
└── autoload.php
```

### Step 2: Configure Web Server

#### Apache

Ensure `mod_rewrite` is enabled and `.htaccess` files are allowed. The included `.htaccess` file handles URL routing automatically.

If `.htaccess` is not working, add this to your virtual host configuration:

```apache
<Directory /path/to/your-site>
    AllowOverride All
    Require all granted
</Directory>
```

#### Nginx

Add this to your server block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}

# Deny access to sensitive files
location ~ /\.(htaccess|git) {
    deny all;
}

location ~ ^/(config|src|vendor|tcms-data)/ {
    deny all;
}
```

### Step 3: Set Permissions

Ensure the web server can write to the data directory:

```bash
chmod -R 755 tcms-data
chown -R www-data:www-data tcms-data  # Adjust user/group for your server
```

### Step 4: Create First Admin User

1. Navigate to `/admin` in your browser
2. You'll see a message: "Setup First User Account"
3. Enter your email and password
4. Click "Sign in" to create the first admin user

### Step 5: Enter License Key

1. After logging in, go to **Settings > License**
2. Enter your license key
3. Click "Save" to activate

## Configuration

After installation, configure Total CMS by creating a `tcms.php` file in your site root. See the [Configuration](docs/configuration) guide for all available options.

### Basic Configuration Example

```php
<?php
// tcms.php
return [
    'siteName' => 'My Website',
    'timezone' => 'America/New_York',
    'debug' => false,
];
```

## Upgrading

To upgrade Total CMS:

1. **Backup your data** - Copy the `tcms-data` directory
2. **Upload new files** - Replace all files except `tcms-data` and `tcms.php`
3. **Clear cache** - Visit `/admin` and clear the cache if prompted

Your content and configuration are preserved in `tcms-data` and `tcms.php`.

## Troubleshooting

### Common Issues

**Blank page or 500 error**
- Check PHP error logs
- Verify PHP version is 8.2+
- Ensure all required extensions are installed

**404 errors on all pages**
- Verify `mod_rewrite` is enabled (Apache)
- Check `.htaccess` file exists and is readable
- Verify Nginx configuration includes try_files directive

**Permission denied errors**
- Check `tcms-data` directory permissions
- Ensure web server user owns the data directory

**License validation fails**
- Verify `curl` extension is installed
- Check firewall allows outbound HTTPS connections
- Ensure domain matches license

### Getting Help

If you encounter issues:

1. Check the [Community Forum](https://community.weavers.space/groups/total-cms)
2. Review the [Configuration](docs/configuration) guide
3. Check PHP error logs for specific error messages
