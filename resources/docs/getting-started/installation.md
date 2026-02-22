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

- `opcache` - PHP bytecode caching
- `exif` - Image metadata extraction

These caching extensions are optional but recommended. You only need one, not all.

- `apcu` - High-performance caching (recommended for production)
- `redis` - Redis caching support
- `memcached` - Memcached caching support

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

Upload the Total CMS `tcms` folder to your web server. The directory structure should look like:

### Step 2: Create First Admin User

1. Navigate to `/tcms/admin` in your browser
2. You'll see a message: "Setup First User Account"
3. Enter your email and password
4. Click "Sign in" to create the first admin user


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

1. Check the [Community Forum](https://community.weavers.space/total-cms)
2. Review the [Configuration](docs/advanced/configuration) guide
3. Check PHP error logs for specific error messages
