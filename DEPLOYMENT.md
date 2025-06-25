# Total CMS Deployment Guide

This guide provides comprehensive instructions for deploying Total CMS in various environments, from development to production.

## Table of Contents

1. [System Requirements](#system-requirements)
2. [Quick Deployment](#quick-deployment)
3. [Production Deployment](#production-deployment)
4. [Development Environment](#development-environment)
5. [Server Configuration](#server-configuration)
6. [Environment Variables](#environment-variables)
7. [Build Process](#build-process)
8. [Security Considerations](#security-considerations)
9. [Performance Optimization](#performance-optimization)
10. [Monitoring and Maintenance](#monitoring-and-maintenance)
11. [Troubleshooting](#troubleshooting)

## System Requirements

### Minimum Requirements

- **PHP**: 8.2.0 or higher
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Node.js**: 18.0+ (for building frontend assets)
- **Yarn**: 1.22+ (package manager)
- **Memory**: 512MB RAM minimum, 1GB+ recommended
- **Storage**: 100MB+ for application, additional space for content

### Recommended Production Requirements

- **PHP**: 8.3+ with OPcache enabled
- **Web Server**: Nginx 1.24+ with HTTP/2 support
- **Node.js**: 20.0+ LTS
- **Memory**: 2GB+ RAM
- **Storage**: SSD storage with sufficient space for growth
- **SSL Certificate**: Required for production environments

### Required PHP Extensions

```bash
# Core extensions
php-json
php-mbstring
php-curl
php-zip
php-gd
php-exif
php-sqlite3

# Recommended extensions
php-opcache
php-redis (if using Redis for caching)
php-imagick (alternative to GD)
```

## Quick Deployment

### 1. Download and Extract

```bash
# Download latest release
wget https://releases.totalcms.co/totalcms-latest.zip
unzip totalcms-latest.zip
cd totalcms/
```

### 2. Set Permissions

```bash
# Make data directory writable
chmod -R 755 tcms-data/
chmod -R 755 resources/cache/

# Ensure web server can write to these directories
chown -R www-data:www-data tcms-data/
chown -R www-data:www-data resources/cache/
```

### 3. Configure Web Server

#### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName totalcms.example.com
    DocumentRoot /var/www/totalcms/public
    
    <Directory /var/www/totalcms/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Optional: Redirect to HTTPS
    Redirect permanent / https://totalcms.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName totalcms.example.com
    DocumentRoot /var/www/totalcms/public
    
    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
    
    <Directory /var/www/totalcms/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name totalcms.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name totalcms.example.com;
    root /var/www/totalcms/public;
    index index.php;

    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Handle PHP files
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        
        # Security
        fastcgi_hide_header X-Powered-By;
    }

    # Handle static files
    location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        add_header Vary "Accept-Encoding";
    }

    # Front controller
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
    
    location ~ /(vendor|config|src|bin|tests)/ {
        deny all;
    }
}
```

## Production Deployment

### 1. Automated Deployment Script

```bash
#!/bin/bash
# deploy.sh - Production deployment script

set -e

DEPLOY_PATH="/var/www/totalcms"
BACKUP_PATH="/var/backups/totalcms"
TEMP_PATH="/tmp/totalcms-deploy"

echo "Starting Total CMS deployment..."

# Create backup
echo "Creating backup..."
mkdir -p $BACKUP_PATH
tar -czf "$BACKUP_PATH/backup-$(date +%Y%m%d-%H%M%S).tar.gz" \
    -C $DEPLOY_PATH \
    tcms-data/ resources/cache/

# Download and extract new version
echo "Downloading latest version..."
mkdir -p $TEMP_PATH
cd $TEMP_PATH
wget -O totalcms.zip https://releases.totalcms.co/totalcms-latest.zip
unzip totalcms.zip

# Copy data directories from current installation
echo "Preserving data..."
cp -r $DEPLOY_PATH/tcms-data/ ./totalcms/
cp -r $DEPLOY_PATH/resources/cache/ ./totalcms/resources/

# Install new version
echo "Installing new version..."
rm -rf $DEPLOY_PATH.old
mv $DEPLOY_PATH $DEPLOY_PATH.old
mv ./totalcms $DEPLOY_PATH

# Set permissions
echo "Setting permissions..."
chown -R www-data:www-data $DEPLOY_PATH
chmod -R 755 $DEPLOY_PATH/tcms-data/
chmod -R 755 $DEPLOY_PATH/resources/cache/

# Restart services
echo "Restarting services..."
systemctl reload nginx
systemctl reload php8.3-fpm

# Cleanup
rm -rf $TEMP_PATH

echo "Deployment completed successfully!"
```

### 2. Environment Configuration

Create environment-specific configuration:

```php
// config/settings.production.php
<?php

return [
    'app' => [
        'debug' => false,
        'environment' => 'production',
    ],
    
    'logging' => [
        'level' => 'error',
        'path' => '/var/log/totalcms/app.log',
    ],
    
    'security' => [
        'ssl_required' => true,
        'session_secure' => true,
        'csrf_protection' => true,
    ],
    
    'performance' => [
        'cache_enabled' => true,
        'compression_enabled' => true,
        'opcache_enabled' => true,
    ],
];
```

### 3. Process Manager (Optional)

For high-traffic sites, consider using a process manager:

```ini
; /etc/supervisor/conf.d/totalcms.conf
[program:totalcms-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/totalcms/bin/worker.php
directory=/var/www/totalcms
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/totalcms/worker.log
```

## Development Environment

### 1. Local Setup

```bash
# Clone repository
git clone https://github.com/joeworkman/totalcms.git
cd totalcms/

# Install dependencies
composer install
yarn install

# Build assets
yarn build

# Start development server
php -S localhost:8000 -t public/
```

### 2. Development Build Process

```bash
# Watch for changes and rebuild automatically
bin/watch.sh

# Or start development server with auto-rebuild
bin/devserver.sh
```

### 3. Docker Development Environment

```dockerfile
# Dockerfile
FROM php:8.3-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    sqlite3 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pdo_sqlite

# Install Node.js and Yarn
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g yarn

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy application
COPY . /var/www/html/
WORKDIR /var/www/html

# Install dependencies and build
RUN composer install --no-dev --optimize-autoloader \
    && yarn install --production \
    && yarn build

# Set permissions
RUN chown -R www-data:www-data tcms-data/ resources/cache/ \
    && chmod -R 755 tcms-data/ resources/cache/

EXPOSE 80
```

```yaml
# docker-compose.yml
version: '3.8'

services:
  totalcms:
    build: .
    ports:
      - "8000:80"
    volumes:
      - ./tcms-data:/var/www/html/tcms-data
      - ./resources/cache:/var/www/html/resources/cache
    environment:
      - APP_ENV=development
      - APP_DEBUG=true
```

## Server Configuration

### PHP Configuration

```ini
; php.ini optimizations
memory_limit = 256M
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
max_input_vars = 3000

; OPcache settings
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
```

### Security Hardening

```bash
# Disable unnecessary PHP functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source

# Hide PHP version
expose_php = Off

# Prevent script execution in upload directories
# Add to .htaccess in tcms-data/
<Files "*.php">
    Order Allow,Deny
    Deny from all
</Files>
```

### File Permissions

```bash
# Secure file permissions
find /var/www/totalcms -type f -exec chmod 644 {} \;
find /var/www/totalcms -type d -exec chmod 755 {} \;

# Make specific directories writable
chmod -R 755 /var/www/totalcms/tcms-data/
chmod -R 755 /var/www/totalcms/resources/cache/

# Secure sensitive files
chmod 600 /var/www/totalcms/config/settings.*.php
```

## Environment Variables

Total CMS supports environment-based configuration:

```bash
# .env file (optional)
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=UTC

# Database configuration (if using external DB)
DB_DRIVER=sqlite
DB_PATH=tcms-data/database.db

# Logging
LOG_LEVEL=error
LOG_PATH=/var/log/totalcms/

# Cache settings
CACHE_DRIVER=file
CACHE_PATH=resources/cache/

# Security
CSRF_PROTECTION=true
SESSION_SECURE=true
SESSION_HTTPONLY=true

# Performance
OPCACHE_ENABLED=true
COMPRESSION_ENABLED=true
```

## Build Process

### Production Build

```bash
# Full production build
composer run build

# This process:
# 1. Builds frontend assets with optimization
# 2. Installs production dependencies
# 3. Removes development files
# 4. Creates distribution bundle
# 5. Sets security permissions
```

### Asset Building

```bash
# Build frontend assets only
composer run esbuild
# or
yarn build

# Watch for changes during development
bin/watch.sh
```

### Bundle Creation

```bash
# Create distribution bundle
composer run bundle

# This creates a bundle hash for integrity verification
```

## Security Considerations

### File Upload Security

```php
// config/upload.php
return [
    'max_size' => '10M',
    'allowed_types' => [
        'image/jpeg',
        'image/png', 
        'image/gif',
        'application/pdf',
        'text/plain',
    ],
    'scan_uploads' => true,
    'quarantine_suspicious' => true,
];
```

### Content Security Policy

```nginx
# Add to Nginx configuration
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self';" always;
```

### SSL/TLS Configuration

```nginx
# Strong SSL configuration
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES256-GCM-SHA384;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 10m;

# HSTS
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

## Performance Optimization

### Caching Strategy

```php
// config/cache.php
return [
    'twig' => [
        'cache' => 'resources/cache/twig/',
        'auto_reload' => false, // Set to true in development
    ],
    
    'content' => [
        'cache' => 'resources/cache/content/',
        'ttl' => 3600, // 1 hour
    ],
    
    'api' => [
        'cache' => 'resources/cache/api/',
        'ttl' => 300, // 5 minutes
    ],
];
```

### Database Optimization

```bash
# SQLite optimization for job queue
sqlite3 tcms-data/jobs.db << EOF
PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA cache_size=10000;
PRAGMA temp_store=memory;
EOF
```

### CDN Integration

```nginx
# Configure CDN-friendly caching
location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header Vary "Accept-Encoding";
    
    # Optional: Add CDN headers
    add_header X-CDN-Cache "HIT" always;
}
```

## Monitoring and Maintenance

### Health Check Endpoint

```php
// Create /health endpoint for monitoring
Route::get('/health', function() {
    return [
        'status' => 'healthy',
        'timestamp' => time(),
        'version' => file_get_contents(__DIR__ . '/version'),
        'checks' => [
            'filesystem' => is_writable('tcms-data/'),
            'database' => file_exists('tcms-data/jobs.db'),
            'cache' => is_writable('resources/cache/'),
        ]
    ];
});
```

### Log Monitoring

```bash
# Monitor application logs
tail -f /var/log/totalcms/app.log

# Monitor web server logs
tail -f /var/log/nginx/access.log /var/log/nginx/error.log
```

### Backup Strategy

```bash
#!/bin/bash
# backup.sh - Automated backup script

BACKUP_DIR="/var/backups/totalcms"
DATE=$(date +%Y%m%d-%H%M%S)

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup data files
tar -czf "$BACKUP_DIR/data-$DATE.tar.gz" \
    -C /var/www/totalcms \
    tcms-data/

# Backup configuration
tar -czf "$BACKUP_DIR/config-$DATE.tar.gz" \
    -C /var/www/totalcms \
    config/

# Remove old backups (keep 30 days)
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete

echo "Backup completed: $BACKUP_DIR/data-$DATE.tar.gz"
```

### Update Process

```bash
#!/bin/bash
# update.sh - Automated update script

# Check for updates
CURRENT_VERSION=$(cat /var/www/totalcms/version)
LATEST_VERSION=$(curl -s https://api.totalcms.co/version)

if [ "$CURRENT_VERSION" != "$LATEST_VERSION" ]; then
    echo "Update available: $CURRENT_VERSION -> $LATEST_VERSION"
    
    # Run deployment script
    /usr/local/bin/deploy.sh
    
    echo "Updated to version $LATEST_VERSION"
else
    echo "Already running latest version: $CURRENT_VERSION"
fi
```

## Troubleshooting

### Common Issues

#### Permission Errors
```bash
# Fix permission issues
sudo chown -R www-data:www-data /var/www/totalcms/
sudo chmod -R 755 /var/www/totalcms/tcms-data/
sudo chmod -R 755 /var/www/totalcms/resources/cache/
```

#### Memory Errors
```ini
; Increase PHP memory limit
memory_limit = 512M

; Or in .htaccess
php_value memory_limit 512M
```

#### Upload Issues
```ini
; Fix upload limits
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
```

### Debug Mode

Enable debug mode for development:

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

### Log Analysis

```bash
# Find PHP errors
grep -i "fatal\|error\|warning" /var/log/totalcms/app.log

# Monitor real-time errors
tail -f /var/log/totalcms/app.log | grep -i error

# Check web server errors
grep -i "error" /var/log/nginx/error.log
```

---

This deployment guide covers all aspects of deploying Total CMS from development to production environments. Follow the appropriate sections based on your deployment needs and environment requirements.