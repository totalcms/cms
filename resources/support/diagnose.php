<?php
/**
 * Total CMS Diagnostic Script
 *
 * USAGE:
 * 1. Upload this file to the customer's public/ directory
 * 2. Access via browser: https://site.com/diagnose.php
 * 3. Review the output to identify issues
 * 4. DELETE the file after diagnosis
 *
 * This script helps diagnose issues when Total CMS fails to load.
 * It runs BEFORE the autoloader, so it can detect missing extensions,
 * corrupted files, etc.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

// Register shutdown function early to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        echo "\n\n== FATAL ERROR DETECTED ==\n";
        echo "Type: " . $error['type'] . "\n";
        echo "Message: " . $error['message'] . "\n";
        echo "File: " . $error['file'] . "\n";
        echo "Line: " . $error['line'] . "\n";
    }
});

$baseDir = dirname(__DIR__); // Go up one level from public/ to the tcms root

echo "=== Total CMS Diagnostic Report ===\n";
echo "Generated: " . date('Y-m-d H:i:s T') . "\n\n";

// PHP Version
echo "== PHP Environment ==\n";
echo "PHP Version: " . PHP_VERSION . " (ID: " . PHP_VERSION_ID . ")\n";
echo "PHP SAPI: " . PHP_SAPI . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";
echo "Max Execution Time: " . ini_get('max_execution_time') . "\n";
echo "Display Errors: " . ini_get('display_errors') . "\n";
echo "Error Reporting: " . error_reporting() . "\n\n";

// OPcache Status
echo "== OPcache Status ==\n";
if (function_exists('opcache_get_status')) {
    $status = @opcache_get_status(false);
    if ($status) {
        echo "OPcache Enabled: Yes\n";
        echo "JIT Enabled: " . (isset($status['jit']['enabled']) && $status['jit']['enabled'] ? 'Yes' : 'No') . "\n";
        echo "JIT Buffer Size: " . ini_get('opcache.jit_buffer_size') . "\n";
        echo "Cached Scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";

        // Offer to clear OPcache
        if (isset($_GET['clear_opcache']) && $_GET['clear_opcache'] === '1') {
            if (function_exists('opcache_reset')) {
                opcache_reset();
                echo "OPcache: CLEARED\n";
            }
        }
    } else {
        echo "OPcache Status: Could not retrieve (may be disabled)\n";
    }
} else {
    echo "OPcache: Not available\n";
}
echo "\n";

// Required Extensions
echo "== Required Extensions ==\n";
$required = [
    'json'      => 'JSON data storage',
    'mbstring'  => 'Unicode/multibyte strings',
    'curl'      => 'HTTP requests (Guzzle)',
    'openssl'   => 'Encryption and TLS',
    'dom'       => 'DOM document processing',
    'libxml'    => 'XML parsing',
    'xml'       => 'XML infrastructure',
    'gd'        => 'Image processing',
    'exif'      => 'Image metadata reading',
    'fileinfo'  => 'MIME type detection',
    'session'   => 'User sessions',
    'hash'      => 'Token validation',
    'pdo'       => 'Database abstraction',
];
$missingExtensions = [];
foreach ($required as $ext => $purpose) {
    $loaded = extension_loaded($ext);
    $status = $loaded ? "OK" : "MISSING <<<";
    echo "$ext: $status ($purpose)\n";
    if (!$loaded) {
        $missingExtensions[] = $ext;
    }
}

// Check GD FreeType support (needed for text on images)
if (extension_loaded('gd')) {
    $gdInfo = gd_info();
    $ftSupport = $gdInfo['FreeType Support'] ?? false;
    echo "gd-freetype: " . ($ftSupport ? "OK" : "MISSING <<<") . " (text on images)\n";
    if (!$ftSupport) {
        $missingExtensions[] = 'gd-freetype';
    }
}
echo "\n";

if (!empty($missingExtensions)) {
    echo "!! WARNING: Missing extensions will cause crashes !!\n";
    echo "!! Please enable in your hosting control panel: " . implode(', ', $missingExtensions) . " !!\n\n";
}

// Optional Extensions
echo "== Optional Extensions ==\n";
$optional = [
    'xmlwriter' => 'QR code generation',
    'opcache'   => 'Bytecode caching (2-5x faster)',
    'apcu'      => 'In-memory caching',
    'redis'     => 'Distributed caching',
    'memcached' => 'Distributed caching',
    'imagick'   => 'Advanced image processing',
    'intl'      => 'Internationalization',
];
foreach ($optional as $ext => $purpose) {
    $loaded = extension_loaded($ext);
    $status = $loaded ? "OK" : "not installed";
    echo "$ext: $status ($purpose)\n";
}
echo "\n";

// Directory Check
echo "== Directory Structure ==\n";
echo "TCMS Directory: $baseDir\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n";

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$tcmsDataDir = $docRoot . '/tcms-data';
echo "tcms-data path: $tcmsDataDir\n";
echo "tcms-data exists: " . (is_dir($tcmsDataDir) ? 'Yes' : 'No') . "\n";
if (is_dir($tcmsDataDir)) {
    echo "tcms-data writable: " . (is_writable($tcmsDataDir) ? 'Yes' : 'No') . "\n";

    // Check subdirectories
    $subdirs = ['collections', 'depot', 'cache', 'logs', '.system'];
    echo "Subdirectories:\n";
    foreach ($subdirs as $subdir) {
        $subpath = $tcmsDataDir . '/' . $subdir;
        if (is_dir($subpath)) {
            echo "  $subdir/: exists, " . (is_writable($subpath) ? 'writable' : 'NOT writable') . "\n";
        } else {
            echo "  $subdir/: missing\n";
        }
    }
}
echo "\n";

$criticalFiles = [
    'autoload.php',
    'vendor/autoload.php',
    'vendor/composer/autoload_real.php',
    'vendor/composer/autoload_static.php',
    'vendor/composer/ClassLoader.php',
    'vendor/composer/platform_check.php',
    'vendor/composer/autoload_files.php',
    'vendor/composer/autoload_psr4.php',
];

echo "== Critical Files Check ==\n";
$missingFiles = [];
foreach ($criticalFiles as $file) {
    $path = $baseDir . '/' . $file;
    if (file_exists($path)) {
        $size = filesize($path);
        echo "$file: OK ({$size} bytes)\n";
    } else {
        echo "$file: MISSING <<<\n";
        $missingFiles[] = $file;
    }
}
echo "\n";

// Count autoload files
$autoloadFilesPath = $baseDir . '/vendor/composer/autoload_files.php';
if (file_exists($autoloadFilesPath)) {
    $autoloadFiles = require $autoloadFilesPath;
    echo "Autoload files count: " . count($autoloadFiles) . "\n\n";
}

// Stop here if there are missing extensions - they will cause crashes
if (!empty($missingExtensions)) {
    echo "== STOPPING: Missing Extensions ==\n";
    echo "Cannot proceed with autoload test until these extensions are enabled:\n";
    foreach ($missingExtensions as $ext) {
        echo "  - $ext\n";
    }
    echo "\nTo enable extensions:\n";
    echo "1. Log into your hosting control panel (cPanel, Plesk, etc.)\n";
    echo "2. Find 'Select PHP Version' or 'PHP Extensions'\n";
    echo "3. Enable the missing extensions listed above\n";
    echo "4. Save and re-run this diagnostic\n";
    echo "\n*** DELETE THIS FILE AFTER DIAGNOSIS ***\n";
    exit;
}

// Stop if critical files are missing
if (!empty($missingFiles)) {
    echo "== STOPPING: Missing Files ==\n";
    echo "Cannot proceed with autoload test. Missing files:\n";
    foreach ($missingFiles as $file) {
        echo "  - $file\n";
    }
    echo "\nPlease re-upload the Total CMS files to fix this issue.\n";
    echo "\n*** DELETE THIS FILE AFTER DIAGNOSIS ***\n";
    exit;
}

// Now try the full autoload
echo "== Full Autoload Test ==\n";
$autoloadPath = $baseDir . '/vendor/autoload.php';

echo "Attempting to load: $autoloadPath\n";

try {
    $loader = require $autoloadPath;
    echo "SUCCESS: Autoload completed!\n";
    echo "Loader class: " . get_class($loader) . "\n\n";

    // If autoload works, try to load the app config
    echo "== Configuration Test ==\n";
    $tcmsConfigPath = $baseDir . '/tcms-data/tcms.php';
    if (file_exists($tcmsConfigPath)) {
        echo "tcms.php: Found\n";
    } else {
        echo "tcms.php: Not found (using defaults)\n";
    }

} catch (Throwable $e) {
    echo "EXCEPTION: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
}


// Test key class loading
echo "\n== Class Loading Test ==\n";
$testClasses = [
    'Slim\\App',
    'DI\\Container',
    'Twig\\Environment',
    'TotalCMS\\App',
    'TotalCMS\\Domain\\Settings\\Settings',
    'TotalCMS\\Domain\\Collection\\CollectionService',
];

foreach ($testClasses as $class) {
    if (class_exists($class, true)) {
        echo "$class: OK\n";
    } else {
        echo "$class: FAILED TO LOAD <<<\n";
    }
}

// Try to actually bootstrap the application
echo "\n== Application Bootstrap Test ==\n";
try {
    // Check for required config files
    $configDir = $baseDir . '/config';
    echo "Config directory: " . (is_dir($configDir) ? 'exists' : 'MISSING') . "\n";

    if (is_dir($configDir)) {
        $configFiles = ['defaults.php', 'container.php', 'routes.php', 'middleware.php', 'settings.php'];
        foreach ($configFiles as $configFile) {
            $configPath = $configDir . '/' . $configFile;
            if (file_exists($configPath)) {
                echo "  $configFile: OK\n";
            } else {
                echo "  $configFile: MISSING <<<\n";
            }
        }
    }

    // Try loading the settings directly (this is what fails first usually)
    echo "\nAttempting to load settings.php...\n";
    $settingsPath = $configDir . '/settings.php';
    if (file_exists($settingsPath)) {
        $settings = require $settingsPath;
        echo "SUCCESS: settings.php loaded\n";
        echo "  env: " . ($settings['env'] ?? 'not set') . "\n";
        echo "  datadir: " . ($settings['datadir'] ?? 'not set') . "\n";
        echo "  domain: " . ($settings['domain'] ?? 'not set') . "\n";

        // Check the datadir
        $datadir = $settings['datadir'] ?? '';
        if ($datadir) {
            echo "\nDatadir check:\n";
            echo "  Path: $datadir\n";
            echo "  Exists: " . (is_dir($datadir) ? 'Yes' : 'No') . "\n";
            if (is_dir($datadir)) {
                echo "  Writable: " . (is_writable($datadir) ? 'Yes' : 'No') . "\n";
            } else {
                echo "  !! Datadir does not exist - this will cause Total CMS to fail !!\n";
            }
        }
    }

    // Try to load the App class and create container
    if (class_exists('TotalCMS\\App')) {
        echo "\nAttempting to create DI container...\n";

        // Load container definitions
        $containerPath = $configDir . '/container.php';
        if (file_exists($containerPath)) {
            $containerBuilder = new \DI\ContainerBuilder();
            $containerBuilder->addDefinitions($containerPath);

            // Try to build the container
            $container = $containerBuilder->build();
            echo "SUCCESS: DI Container created\n";

            // Try to get Config
            echo "\nAttempting to load Config...\n";
            try {
                $config = $container->get('TotalCMS\\Support\\Config');
                echo "SUCCESS: Config loaded\n";
                echo "  datadir: " . $config->datadir . "\n";
                echo "  env: " . $config->env . "\n";
            } catch (Throwable $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
            }

            // Try to get the Slim App
            echo "\nAttempting to create Slim App...\n";
            try {
                $app = $container->get('Slim\\App');
                echo "SUCCESS: Slim App created\n";
            } catch (Throwable $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
                echo "File: " . $e->getFile() . "\n";
                echo "Line: " . $e->getLine() . "\n";
            }
        }
    }
} catch (Throwable $e) {
    echo "BOOTSTRAP FAILED: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
}

// Check for tcms.php in document root
echo "\n== tcms.php Configuration ==\n";
$tcmsPhpPath = $docRoot . '/tcms.php';
echo "tcms.php path: $tcmsPhpPath\n";
if (file_exists($tcmsPhpPath)) {
    echo "Status: Found\n";
    // Don't re-require it since settings.php already did
} else {
    echo "Status: NOT FOUND\n";
    echo "\nFor Stacks integration, create this file with:\n";
    echo "-----------------------------------\n";
    echo "<?php\n";
    echo "return [\n";
    echo "    'datadir' => '$docRoot/tcms-data',\n";
    echo "];\n";
    echo "-----------------------------------\n";
}

echo "\n== PHP Configuration Details ==\n";
echo "opcache.enable: " . ini_get('opcache.enable') . "\n";
echo "opcache.jit: " . ini_get('opcache.jit') . "\n";
echo "opcache.jit_buffer_size: " . ini_get('opcache.jit_buffer_size') . "\n";
echo "opcache.validate_timestamps: " . ini_get('opcache.validate_timestamps') . "\n";
echo "open_basedir: " . (ini_get('open_basedir') ?: '(none)') . "\n";
echo "disable_functions: " . (ini_get('disable_functions') ?: '(none)') . "\n";

echo "\n== Additional Actions ==\n";
echo "Clear OPcache: Add ?clear_opcache=1 to the URL\n";

echo "\n== End of Diagnostic Report ==\n";
echo "\n*** DELETE THIS FILE AFTER DIAGNOSIS ***\n";
