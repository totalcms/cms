# Total CMS Troubleshooting Guide

This guide provides solutions to common issues, debugging techniques, and frequently asked questions for Total CMS.

## Table of Contents

1. [Quick Diagnostic Checklist](#quick-diagnostic-checklist)
2. [Installation Issues](#installation-issues)
3. [Configuration Problems](#configuration-problems)
4. [Performance Issues](#performance-issues)
5. [File Upload Problems](#file-upload-problems)
6. [Database and Storage Issues](#database-and-storage-issues)
7. [Frontend/JavaScript Issues](#frontendjavascript-issues)
8. [Security and Permissions](#security-and-permissions)
9. [API and Integration Issues](#api-and-integration-issues)
10. [Development and Build Issues](#development-and-build-issues)
11. [Frequently Asked Questions](#frequently-asked-questions)
12. [Getting Help](#getting-help)

## Quick Diagnostic Checklist

Before diving into specific issues, run through this quick checklist:

### System Requirements
```bash
# Check PHP version (requires 8.2+)
php --version

# Check required PHP extensions
php -m | grep -E "(json|mbstring|curl|zip|gd|exif|sqlite3)"

# Check file permissions
ls -la tcms-data/
ls -la resources/cache/

# Check web server configuration
curl -I http://your-totalcms-site.com/
```

### Basic Health Check
```bash
# Verify installation
composer run test:build

# Check logs for errors
tail -50 /var/log/totalcms/app.log

# Test database connection
php -r "new PDO('sqlite:tcms-data/jobs.db'); echo 'Database OK';"
```

### Debug Mode
Enable debug mode for detailed error information:

```php
// config/settings.local.php
return [
    'app' => [
        'debug' => true,
        'environment' => 'development',
    ],
    'logging' => [
        'level' => 'debug',
        'display_errors' => true,
    ],
];
```

## Installation Issues

### Issue: Composer Installation Fails

**Symptoms:**
- `composer install` returns dependency conflicts
- Memory limit errors during installation
- SSL certificate verification failures

**Solutions:**

```bash
# Memory limit issues
php -d memory_limit=512M composer install

# SSL issues
composer config -g repo.packagist composer https://packagist.org

# Clear composer cache
composer clear-cache
composer install

# Alternative: use --no-dev for production
composer install --no-dev --optimize-autoloader
```

### Issue: Web Server 404 Errors

**Symptoms:**
- All pages return 404 Not Found
- Admin interface not accessible

**Solutions:**

**Apache:**
```apache
# Ensure mod_rewrite is enabled
sudo a2enmod rewrite
sudo systemctl restart apache2

# Check .htaccess files exist
ls -la public/.htaccess
ls -la .htaccess

# Verify AllowOverride is set
<Directory /var/www/totalcms/public>
    AllowOverride All
    Require all granted
</Directory>
```

**Nginx:**
```nginx
# Verify PHP-FPM is running
sudo systemctl status php8.3-fpm

# Check Nginx configuration
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# Test configuration
sudo nginx -t
sudo systemctl reload nginx
```

### Issue: White Screen of Death

**Symptoms:**
- Blank white page with no content
- No error messages visible

**Solutions:**

```bash
# Enable error display
echo "ini_set('display_errors', 1); error_reporting(E_ALL);" > debug.php
# Place debug.php in public/ and visit it

# Check PHP error logs
tail -f /var/log/php/error.log

# Increase memory limit
echo "memory_limit = 256M" >> php.ini

# Check for fatal errors in autoloading
composer dump-autoload --optimize
```

## Configuration Problems

### Issue: Environment Configuration Not Loading

**Symptoms:**
- Settings not being applied
- Default values always used
- Environment-specific behavior not working

**Solutions:**

```php
// Verify configuration loading order
// config/settings.php (base)
// config/settings.{environment}.php (environment-specific)
// .env file (if using)

// Debug configuration loading
var_dump($container->get('settings'));

// Check file permissions
chmod 644 config/settings*.php

// Verify environment detection
echo $_ENV['APP_ENV'] ?? 'production';
```

### Issue: Twig Template Errors

**Symptoms:**
- Template not found errors
- Syntax errors in templates
- Missing template variables

**Solutions:**

```bash
# Clear Twig cache
rm -rf resources/cache/twig/*

# Check template paths
ls -la resources/templates/

# Verify template syntax
# Use Twig playground: /admin/utils/twig-playground

# Debug template variables
{{ dump() }}  # In development templates
```

### Issue: Database Connection Problems

**Symptoms:**
- SQLite database errors
- Job queue not working
- Database locked errors

**Solutions:**

```bash
# Check database file exists and is writable
ls -la tcms-data/jobs.db
chmod 664 tcms-data/jobs.db

# Test database connection
sqlite3 tcms-data/jobs.db ".schema"

# Fix database lock issues
rm tcms-data/jobs.db-wal
rm tcms-data/jobs.db-shm

# Recreate database
rm tcms-data/jobs.db
# Restart application to recreate
```

## Performance Issues

### Issue: Slow Page Load Times

**Symptoms:**
- Pages take >3 seconds to load
- High CPU usage
- Memory consumption increasing

**Diagnosis:**

```bash
# Enable debug mode to see execution time
# Add to templates: {{ dump(_profiler) }}

# Check PHP-FPM status
sudo systemctl status php8.3-fpm

# Monitor server resources
top -p $(pgrep php)
```

**Solutions:**

```bash
# Enable OPcache
echo "opcache.enable=1" >> php.ini
echo "opcache.memory_consumption=128" >> php.ini

# Optimize autoloader
composer dump-autoload --optimize --classmap-authoritative

# Enable compression
# In nginx:
gzip on;
gzip_types text/css application/javascript application/json;

# Increase PHP memory
echo "memory_limit = 512M" >> php.ini
```

### Issue: Twig Template Compilation Slow

**Symptoms:**
- First page load very slow
- Template compilation errors
- High disk I/O during page loads

**Solutions:**

```bash
# Pre-compile templates
bin/warm-cache.sh

# Check cache directory permissions
chmod -R 755 resources/cache/twig/
chown -R www-data:www-data resources/cache/

# Disable auto-reload in production
// config/twig.php
'auto_reload' => false,
'cache' => 'resources/cache/twig/',
```

### Issue: Large File Processing Timeouts

**Symptoms:**
- Upload timeouts for large files
- Image processing failures
- Memory exhaustion during file operations

**Solutions:**

```ini
; php.ini adjustments
max_execution_time = 300
memory_limit = 512M
upload_max_filesize = 50M
post_max_size = 50M

; For image processing
max_input_vars = 3000
```

```bash
# Nginx timeout settings
client_max_body_size 50M;
client_body_timeout 300s;
fastcgi_read_timeout 300s;
```

## File Upload Problems

### Issue: File Uploads Fail Silently

**Symptoms:**
- Upload form submits but no file saved
- No error messages displayed
- Files larger than expected limit fail

**Diagnosis:**

```php
// Add to upload debug
echo "Upload errors: " . $_FILES['file']['error'] . "\n";
echo "Max upload size: " . ini_get('upload_max_filesize') . "\n";
echo "Max post size: " . ini_get('post_max_size') . "\n";
```

**Solutions:**

```php
// Check upload error codes
switch ($_FILES['file']['error']) {
    case UPLOAD_ERR_INI_SIZE:
        echo "File too large (php.ini limit)";
        break;
    case UPLOAD_ERR_FORM_SIZE:
        echo "File too large (form limit)";
        break;
    case UPLOAD_ERR_PARTIAL:
        echo "File partially uploaded";
        break;
    case UPLOAD_ERR_NO_FILE:
        echo "No file uploaded";
        break;
    case UPLOAD_ERR_NO_TMP_DIR:
        echo "Missing temp directory";
        break;
    case UPLOAD_ERR_CANT_WRITE:
        echo "Cannot write to disk";
        break;
}
```

### Issue: Image Processing Errors

**Symptoms:**
- Uploaded images not displaying
- Thumbnail generation fails
- "Image manipulation failed" errors

**Solutions:**

```bash
# Check GD extension
php -m | grep -i gd

# Install additional image libraries
# Ubuntu/Debian:
sudo apt-get install php8.3-gd php8.3-imagick

# CentOS/RHEL:
sudo yum install php-gd php-imagick

# Check image processing limits
echo "Memory limit: " . ini_get('memory_limit');
echo "Max execution time: " . ini_get('max_execution_time');
```

### Issue: File Permission Errors

**Symptoms:**
- "Permission denied" errors
- Cannot create files/directories
- Upload folder not writable

**Solutions:**

```bash
# Set correct permissions
chmod -R 755 tcms-data/
chmod -R 755 resources/cache/
chown -R www-data:www-data tcms-data/ resources/cache/

# Check SELinux (if applicable)
sudo setsebool -P httpd_can_network_connect 1
sudo chcon -R -t httpd_exec_t tcms-data/

# Verify web server user
ps aux | grep -E "(apache|nginx|www-data)"
```

## Database and Storage Issues

### Issue: SQLite Database Lock Errors

**Symptoms:**
- "Database is locked" errors
- Job queue stops processing
- Concurrent access failures

**Solutions:**

```bash
# Check for stale lock files
ls -la tcms-data/jobs.db*
rm tcms-data/jobs.db-wal tcms-data/jobs.db-shm

# Optimize SQLite settings
sqlite3 tcms-data/jobs.db << EOF
PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA busy_timeout=5000;
EOF

# Restart web server to clear connections
sudo systemctl restart nginx php8.3-fpm
```

### Issue: JSON File Corruption

**Symptoms:**
- "Invalid JSON" errors
- Collection data not loading
- Objects missing from admin interface

**Solutions:**

```bash
# Validate JSON files
find tcms-data/ -name "*.json" -exec echo "Checking {}" \; -exec python -m json.tool {} > /dev/null \;

# Backup and restore from backup
cp tcms-data/collection/object.json tcms-data/collection/object.json.backup

# Manual JSON repair
jq . tcms-data/collection/object.json > temp.json && mv temp.json tcms-data/collection/object.json
```

### Issue: Disk Space Issues

**Symptoms:**
- "No space left on device" errors
- File uploads fail
- Cache cannot be written

**Solutions:**

```bash
# Check disk usage
df -h
du -sh tcms-data/ resources/cache/

# Clean up old files
find tcms-data/ -name "*.backup" -mtime +30 -delete
find resources/cache/ -type f -mtime +7 -delete

# Rotate logs
logrotate /etc/logrotate.d/totalcms
```

## Frontend/JavaScript Issues

### Issue: JavaScript Errors in Console

**Symptoms:**
- Form fields not working
- AJAX requests failing
- Console shows JavaScript errors

**Diagnosis:**

```javascript
// Enable debug mode in browser
localStorage.setItem('totalcms_debug', 'true');

// Check for JavaScript errors
console.log('Errors:', window.jsErrors);

// Verify assets are loading
console.log('TotalCMS loaded:', typeof window.TotalCMS);
```

**Solutions:**

```bash
# Rebuild JavaScript assets
yarn build

# Check for build errors
bin/build-assets.sh

# Verify asset paths
ls -la public/assets/

# Clear browser cache
# Or append cache-busting parameter
```

### Issue: Form Fields Not Initializing

**Symptoms:**
- Rich text editors not loading
- Select fields showing as plain dropdowns
- Custom field behavior missing

**Solutions:**

```javascript
// Debug form initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('Forms found:', document.querySelectorAll('.totalform'));
    console.log('TotalFormManager:', window.TotalFormManager);
});

// Manual initialization
const form = document.querySelector('.totalform');
if (form && !form.totalform) {
    new TotalForm(form);
}
```

### Issue: Asset Loading Failures

**Symptoms:**
- CSS styles not applied
- JavaScript functionality missing
- 404 errors for asset files

**Solutions:**

```bash
# Check asset compilation
node esbuild.config.js

# Verify asset paths in templates
grep -r "assets/" resources/templates/

# Check web server configuration for static files
# Nginx:
location ~* \.(css|js|png|jpg|jpeg|gif|svg)$ {
    expires 1y;
    add_header Cache-Control "public";
}
```

## Security and Permissions

### Issue: CSRF Token Errors

**Symptoms:**
- "CSRF token mismatch" errors
- Forms not submitting
- API requests failing

**Solutions:**

```php
// Debug CSRF tokens
echo "Token from form: " . $_POST['_token'] ?? 'missing';
echo "Token from session: " . $session->get('csrf_token');

// Clear session
session_destroy();

// Verify CSRF middleware is enabled
// Check middleware configuration
```

### Issue: File Upload Security Blocking

**Symptoms:**
- Valid files rejected as "unsafe"
- Unexpected file type restrictions
- Upload validation errors

**Solutions:**

```php
// Check file validation rules
// config/upload.php
'allowed_types' => [
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/pdf',
    // Add your required types
],

// Disable strict validation (development only)
'strict_validation' => false,
```

### Issue: Session Problems

**Symptoms:**
- Frequent logouts
- "Session expired" messages
- Authentication state not persisting

**Solutions:**

```php
// Check session configuration
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 0);

// Verify session storage
ls -la /tmp/sess_*

// Debug session data
var_dump($_SESSION);
```

## API and Integration Issues

### Issue: API Endpoints Not Responding

**Symptoms:**
- 404 errors for API routes
- JSON responses not formatted correctly
- CORS errors in browser

**Solutions:**

```bash
# Test API endpoint directly
curl -X GET http://your-site.com/api/collections \
     -H "Accept: application/json"

# Check route registration
php bin/console.php routes

# Verify CORS configuration
# In middleware configuration:
'allowed_origins' => ['*'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
```

### Issue: Webhook Delivery Failures

**Symptoms:**
- External webhooks not receiving data
- Timeout errors in webhook calls
- Authentication failures

**Solutions:**

```php
// Test webhook endpoint
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://webhook-url.com');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Webhook-Secret: your-secret'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Response: $response\n";
echo "HTTP Code: $httpCode\n";
```

## Development and Build Issues

### Issue: Build Process Failures

**Symptoms:**
- `yarn build` fails
- ESBuild compilation errors
- Missing dependencies

**Solutions:**

```bash
# Clean and reinstall dependencies
rm -rf node_modules/ yarn.lock
yarn install

# Check Node.js version (requires 18+)
node --version

# Debug build process
yarn build --verbose

# Check for conflicting global packages
npm list -g --depth=0
```

### Issue: Hot Reload Not Working

**Symptoms:**
- Changes not reflected in browser
- Watch process not detecting files
- Build process not running

**Solutions:**

```bash
# Restart watch process
pkill -f "bin/watch.sh"
bin/watch.sh

# Check file permissions
chmod +x bin/watch.sh bin/build-assets.sh

# Verify file watching
inotifywatch tcms-data/ &
# Make changes and observe output
```

### Issue: Test Failures

**Symptoms:**
- Pest tests failing
- Database errors in tests
- Test isolation problems

**Solutions:**

```bash
# Run tests with verbose output
vendor/bin/pest --verbose

# Clear test data
rm -rf tests/tcms-data/
mkdir tests/tcms-data/

# Run specific test
vendor/bin/pest tests/Feature/CollectionTest.php

# Debug test environment
vendor/bin/pest --debug
```

## Frequently Asked Questions

### Q: How do I reset admin password?

**A:** Create a password reset script:

```php
// reset-password.php
require 'autoload.php';

$app = require 'config/bootstrap.php';
$loginService = $app->getContainer()->get(LoginService::class);

// Reset to 'admin' / 'password'
$loginService->resetPassword('admin', 'password');
echo "Password reset to 'password'\n";
```

### Q: How do I migrate from older version?

**A:** Follow the migration process:

```bash
# Backup current installation
tar -czf totalcms-backup.tar.gz tcms-data/ resources/cache/

# Download new version
wget https://releases.totalcms.co/totalcms-latest.zip

# Extract and preserve data
unzip totalcms-latest.zip
cp -r old-installation/tcms-data/ totalcms/
cp -r old-installation/resources/cache/ totalcms/resources/

# Run any migration scripts
php bin/migrate.php
```

### Q: How do I enable HTTPS?

**A:** Configure SSL in your web server:

```nginx
# Nginx SSL configuration
server {
    listen 443 ssl http2;
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    
    # Force HTTPS in Total CMS
    fastcgi_param HTTPS on;
}
```

### Q: How do I customize the admin interface?

**A:** Create custom templates and styles:

```bash
# Copy default templates
cp -r resources/templates/admin/ custom-templates/

# Modify custom templates
# Override in config:
'twig' => [
    'paths' => [
        'custom-templates/',
        'resources/templates/'
    ]
]
```

### Q: How do I backup and restore data?

**A:** Use the built-in backup system:

```bash
# Create backup
tar -czf backup-$(date +%Y%m%d).tar.gz tcms-data/ resources/cache/

# Restore backup
tar -xzf backup-20240101.tar.gz

# Or use API
curl -X POST http://your-site.com/api/backup
curl -X POST http://your-site.com/api/restore -d @backup.json
```

### Q: How do I optimize for high traffic?

**A:** Implement caching and optimization:

```bash
# Enable OPcache
echo "opcache.enable=1" >> php.ini

# Use Redis for sessions (optional)
echo "session.save_handler=redis" >> php.ini
echo "session.save_path=tcp://127.0.0.1:6379" >> php.ini

# Configure reverse proxy caching
# Nginx with FastCGI cache or Varnish
```

### Q: How do I add custom field types?

**A:** Follow the extension development guide:

1. Create Property Data class
2. Create Form Field component  
3. Create JavaScript component
4. Register field type in configuration
5. Add to schema validation

See [EXTENSION_DEVELOPMENT.md](EXTENSION_DEVELOPMENT.md) for detailed instructions.

### Q: How do I integrate with external APIs?

**A:** Create service classes for API integration:

```php
// src/Domain/Integration/ExternalApiService.php
final class ExternalApiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey
    ) {}
    
    public function fetchData(string $endpoint): array
    {
        $response = $this->httpClient->request('GET', $endpoint, [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey]
        ]);
        
        return json_decode($response->getBody(), true);
    }
}
```

## Getting Help

### Support Channels

1. **Documentation**: Check all documentation files in this repository
2. **GitHub Issues**: [Report bugs and request features](https://github.com/joeworkman/totalcms/issues)
3. **Community Forum**: [Join discussions](https://forum.totalcms.co)
4. **Professional Support**: contact@totalcms.co

### Providing Debug Information

When requesting help, include:

1. **System Information**:
   ```bash
   php --version
   uname -a
   cat /etc/os-release
   ```

2. **Error Logs**:
   ```bash
   tail -50 /var/log/totalcms/app.log
   tail -50 /var/log/nginx/error.log
   ```

3. **Configuration**:
   ```bash
   # Sanitized config (remove sensitive data)
   grep -v "password\|secret\|key" config/settings.php
   ```

4. **Steps to Reproduce**: Clear description of the issue and steps to reproduce it

### Contributing Fixes

If you solve an issue:

1. Document the solution
2. Create tests if applicable
3. Submit a pull request
4. Update this troubleshooting guide

---

This troubleshooting guide covers the most common issues encountered with Total CMS. For additional help, refer to the other documentation files or reach out through the support channels listed above.