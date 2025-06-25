# Configuration

Total CMS uses a hierarchical configuration system based on PHP files. This allows for flexible environment-specific settings while maintaining secure defaults.

## Configuration Load Order

Configuration files are loaded in a specific order, with later files overriding earlier ones:

1. `config/defaults.php` - Base application defaults
2. `config/env.php` - Environment detection
3. `config/{environment}.php` - Environment-specific settings (e.g., `production.php`, `development.php`)
4. `{DOCUMENT_ROOT}/env.php` - Server-specific overrides (optional)

This hierarchy allows you to:
- Set safe defaults for all environments
- Override settings per environment (dev, staging, production)
- Apply server-specific settings without modifying the codebase

## Core Configuration Files

### defaults.php

The base configuration file that sets application-wide defaults:

```php
<?php
// config/defaults.php

// Error reporting (production safe)
error_reporting(0);
ini_set('display_errors', '0');

// Locale settings
setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'en_US');

// Core settings
$settings = [
    'env' => 'prod',
    'locale' => 'en_US',
    'timezone' => 'UTC',
    'debug' => false,
];

// Database settings
$settings['db'] = [
    'driver' => 'sqlite',
    'database' => __DIR__ . '/../data/database.sqlite',
];

// Paths
$settings['paths'] = [
    'root' => dirname(__DIR__),
    'public' => dirname(__DIR__) . '/public',
    'cache' => dirname(__DIR__) . '/cache',
    'logs' => dirname(__DIR__) . '/logs',
    'data' => dirname(__DIR__) . '/tcms-data',
];
```

### Environment Configuration

Environment-specific files override defaults:

```php
<?php
// config/development.php

// Enable debugging in development
error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['debug'] = true;
$settings['env'] = 'dev';

// Development database
$settings['db']['database'] = __DIR__ . '/../data/dev-database.sqlite';

// Disable caching
$settings['cache']['enabled'] = false;

// Verbose logging
$settings['logger']['level'] = 'debug';
```

## Configuration Categories

### Application Settings

```php
// Basic application settings
$settings['app'] = [
    'name' => 'Total CMS',
    'version' => '3.0.0',
    'timezone' => 'America/New_York',
    'locale' => 'en_US',
    'charset' => 'UTF-8',
];

// URL and domain settings
$settings['domain'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$settings['url'] = 'https://' . $settings['domain'];
$settings['api'] = $settings['url'] . '/api/v3';
```

### Security Settings

```php
// Security configuration
$settings['security'] = [
    // Session settings
    'session' => [
        'name' => 'TCMS_SESSION',
        'lifetime' => 7200, // 2 hours
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    
    // CSRF protection
    'csrf' => [
        'enabled' => true,
        'token_name' => 'csrf_token',
        'header_name' => 'X-CSRF-Token',
    ],
    
    // Password hashing
    'password' => [
        'algorithm' => PASSWORD_BCRYPT,
        'options' => ['cost' => 12],
    ],
];
```

### Storage Settings

```php
// File storage configuration
$settings['storage'] = [
    // Data storage
    'data_path' => $settings['paths']['data'],
    'collections_path' => $settings['paths']['data'] . '/collections',
    'schemas_path' => $settings['paths']['data'] . '/schemas',
    
    // Media storage
    'media' => [
        'path' => $settings['paths']['public'] . '/media',
        'url' => '/media',
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
        'max_upload_size' => '10M',
    ],
    
    // Image processing
    'images' => [
        'driver' => 'gd', // or 'imagick'
        'quality' => 85,
        'cache' => true,
        'cache_path' => $settings['paths']['cache'] . '/images',
    ],
];
```

### Cache Configuration

```php
// Caching settings
$settings['cache'] = [
    'enabled' => true,
    'driver' => 'file', // file, redis, memcached
    'ttl' => 3600, // 1 hour default
    
    // File cache settings
    'file' => [
        'path' => $settings['paths']['cache'],
    ],
    
    // Redis settings (if using Redis)
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
    ],
    
    // Template cache
    'templates' => [
        'enabled' => true,
        'path' => $settings['paths']['cache'] . '/twig',
    ],
];
```

### Database Configuration

```php
// Database settings
$settings['db'] = [
    // SQLite (default for job queue)
    'sqlite' => [
        'driver' => 'sqlite',
        'database' => $settings['paths']['data'] . '/jobqueue.db',
    ],
    
    // MySQL (optional)
    'mysql' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'database' => 'totalcms',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ],
];
```

### Logging Configuration

```php
// Logging settings
$settings['logger'] = [
    'name' => 'totalcms',
    'path' => $settings['paths']['logs'] . '/app.log',
    'level' => 'warning', // debug, info, notice, warning, error, critical
    'max_files' => 30,
    
    // Separate logs for different components
    'channels' => [
        'security' => $settings['paths']['logs'] . '/security.log',
        'api' => $settings['paths']['logs'] . '/api.log',
        'jobs' => $settings['paths']['logs'] . '/jobs.log',
    ],
];
```

### API Configuration

```php
// API settings
$settings['api'] = [
    'version' => 'v3',
    'rate_limit' => [
        'enabled' => true,
        'requests_per_minute' => 60,
        'burst' => 10,
    ],
    'cors' => [
        'enabled' => true,
        'origins' => ['*'],
        'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'credentials' => true,
        'max_age' => 86400,
    ],
];
```

### Email Configuration

```php
// Email settings
$settings['mail'] = [
    'driver' => 'smtp', // smtp, sendmail, mail
    'from' => [
        'address' => 'noreply@example.com',
        'name' => 'Total CMS',
    ],
    
    // SMTP settings
    'smtp' => [
        'host' => 'smtp.mailtrap.io',
        'port' => 2525,
        'encryption' => 'tls', // tls or ssl
        'username' => '',
        'password' => '',
    ],
];
```

### License Configuration

```php
// License settings
$settings['license'] = [
    'enabled' => true,
    'api_url' => 'https://license.totalcms.co/api',
    'cache_ttl' => 86400, // 24 hours
    'trial_days' => 30,
    'features' => [
        'collections' => ['blog', 'image', 'gallery', 'file'],
        'max_objects' => 1000,
        'custom_schemas' => false,
    ],
];
```

## Environment Variables

You can also use environment variables for sensitive configuration:

```php
// Using environment variables
$settings['api_key'] = $_ENV['TOTALCMS_API_KEY'] ?? null;
$settings['db']['password'] = $_ENV['DB_PASSWORD'] ?? '';
$settings['mail']['smtp']['password'] = $_ENV['SMTP_PASSWORD'] ?? '';
```

## Creating Custom Configuration

### Server-Specific Configuration

Create a file at `{DOCUMENT_ROOT}/env.php` for server-specific settings:

```php
<?php
// /path/to/document/root/env.php

// Override domain for this server
$settings['domain'] = 'mysite.com';
$settings['url'] = 'https://mysite.com';

// Production database
$settings['db']['mysql'] = [
    'host' => 'localhost',
    'database' => 'prod_totalcms',
    'username' => 'prod_user',
    'password' => 'secure_password',
];

// Disable debug mode
$settings['debug'] = false;
error_reporting(0);
```

### Feature Flags

Enable/disable features via configuration:

```php
// Feature flags
$settings['features'] = [
    'blog' => true,
    'ecommerce' => false,
    'multi_language' => true,
    'api_v4' => false,
    'beta_features' => false,
];

// Check feature in code
if ($settings['features']['ecommerce'] ?? false) {
    // Enable e-commerce functionality
}
```

## Configuration Best Practices

### 1. Never Commit Sensitive Data

Keep sensitive configuration out of version control:

```php
// BAD - Don't commit passwords
$settings['db']['password'] = 'my-secret-password';

// GOOD - Use environment variables
$settings['db']['password'] = $_ENV['DB_PASSWORD'] ?? '';
```

### 2. Use Environment Detection

Automatically detect the environment:

```php
// config/env.php
$settings['env'] = match ($_SERVER['HTTP_HOST'] ?? '') {
    'localhost', '127.0.0.1' => 'development',
    'staging.example.com' => 'staging',
    default => 'production',
};
```

### 3. Validate Configuration

Add configuration validation:

```php
// Validate required settings
$required = ['domain', 'paths.data', 'paths.cache'];
foreach ($required as $key) {
    if (empty(array_get($settings, $key))) {
        throw new ConfigurationException("Missing required config: {$key}");
    }
}
```

### 4. Document Settings

Always document custom configuration:

```php
// Custom image processing settings
$settings['image_processing'] = [
    // Maximum dimension for auto-resize (0 = disabled)
    'max_dimension' => 2048,
    
    // Automatically convert uploads to WebP
    'auto_webp' => true,
    
    // Strip EXIF data from uploads
    'strip_exif' => true,
];
```

## Accessing Configuration

### In PHP Code

```php
// Get the container
$container = $app->getContainer();

// Access settings
$settings = $container->get('settings');
$debug = $settings['debug'];
$dbConfig = $settings['db'];

// Using helper function
$value = config('app.name'); // "Total CMS"
$apiUrl = config('api.url', 'http://localhost'); // With default
```

### In Twig Templates

```twig
{# Access configuration in templates #}
{{ config.app.name }}
{{ config.domain }}

{# Check feature flags #}
{% if config.features.blog %}
    {# Show blog features #}
{% endif %}
```

## Troubleshooting Configuration

### Debug Configuration Loading

```php
// Add to config/env.php for debugging
if ($_GET['debug_config'] ?? false) {
    echo '<pre>';
    print_r($settings);
    echo '</pre>';
    exit;
}
```

### Common Issues

1. **Configuration not loading**: Check file permissions and path
2. **Settings being overridden**: Check the load order
3. **Environment detection failing**: Verify $_SERVER variables
4. **Cache not clearing**: Manually clear cache directory

## Configuration Reference

For a complete list of all configuration options, see the `config/defaults.php` file in your Total CMS installation. Each setting is documented with its purpose and acceptable values.