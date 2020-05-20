<?php

// Configure defaults for the whole application.

// Cloudflare IP address header
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
}

// Error reporting
error_reporting(0);
ini_set('display_errors', '0');

// locale
setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'en_US');

// Timezone
if (!ini_get('date.timezone')) {
    // default to UTC timezone
    date_default_timezone_set(DateTimeZone::listIdentifiers(DateTimeZone::UTC)[0]);
}

// JSON fix for saving float values
ini_set('serialize_precision', '-1');

// Settings
$settings = [];

// Default env to production
$settings['env'] = 'production';

// Path settings
$settings['root']   = dirname(__DIR__);
$settings['temp']   = $settings['root'] . '/tmp';
$settings['public'] = $settings['root'] . '/api';

// Clean up trailing slashes in DOCUMENT_ROOT
$settings['docroot'] = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);

// Path to cms data folder
$settings['datadir'] = $settings['docroot'] . '/tcms-data';

// Error Handling
$settings['error'] = [
    // Should be set to false in production
    'display_error_details' => true,
    // Parameter is passed to the default ErrorHandler
    // View in rendered output by enabling the "displayErrorDetails" setting.
    // For the console and unit tests it should be disable too
    'log_errors' => true,
    // Display error details in error log
    'log_error_details' => true,
];

// Application settings
$settings['app'] = [
    'secret' => '{{app_secret}}',
];

// Logger settings
$settings['logger'] = [
    'name'            => 'totalcms',
    'path'            => $settings['data'] . '/logs',
    'filename'        => 'totalcms.log',
    'level'           => \Monolog\Logger::ERROR,
    'file_permission' => 0775,
];

// View settings
$settings['twig'] = [
    'paths' => [
        $settings['root'] . '/templates',
        __DIR__ . '/../templates',
    ],
    'options' => [
        'debug' => false,
        // Should be set to true in production
        'cache_enabled' => true,
        'cache_path'    => $settings['temp'] . '/twig',
    ],
];

// Session
$settings['session'] = [
    'name'         => 'totalcms',
    'cache_expire' => 0,
];

// Database settings
// $settings['db'] = [
//     'driver'    => \Cake\Database\Driver\Mysql::class,
//     'host'      => 'localhost',
//     'encoding'  => 'utf8mb4',
//     'collation' => 'utf8mb4_unicode_ci',
//     // Enable identifier quoting
//     'quoteIdentifiers' => true,
//     // Set to null to use MySQL servers timezone
//     'timezone' => null,
//     // Disable meta data cache
//     'cacheMetadata' => false,
//     // Disable query logging
//     'log' => false,
//     // PDO options
//     'flags' => [
//         // Turn off persistent connections
//         PDO::ATTR_PERSISTENT => false,
//         // Enable exceptions
//         PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//         // Emulate prepared statements
//         PDO::ATTR_EMULATE_PREPARES => true,
//         // Set default fetch mode to array
//         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
//     ],
// ];

// E-Mail settings
$settings['smtp'] = [
    'type'      => 'smtp',
    'host'      => '127.0.0.1',
    'port'      => '25',
    'secure'    => '',
    'from'      => 'from@example.com',
    'from_name' => 'My name',
    'to'        => 'to@example.com',
];

// Console commands
// $settings['commands'] = [
//     \App\Console\TwigCompilerCommand::class,
//     \App\Console\SchemaDumpCommand::class,
// ];

return $settings;
