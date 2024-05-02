<?php

// Configure defaults for the whole application.

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

// Cloudflare IP address header
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
}

// Settings
$settings = [];

// Default env to production
$settings['env']    = 'prod';
$settings['locale'] = 'en_US';

$settings['domain'] = 'example.com';
$settings['url']    = 'https://' . $settings['domain'];
$settings['api']    = $settings['url'] . '/api';

// Path settings
$settings['root']     = dirname(__DIR__);
$settings['tmpdir']   = $settings['root'] . '/tmp';
$settings['public']   = $settings['root'] . '/public';
$settings['template'] = $settings['root'] . '/templates';
$settings['schemas']  = $settings['root'] . '/schemas';
$settings['cachedir'] = $settings['root'] . '/cache';

// Clean up trailing slashes in DOCUMENT_ROOT
$settings['docroot'] = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);

// Path to cms data folder
$settings['datadir'] = $settings['docroot'] . '/tcms-data';

// Error Handling
$settings['error'] = [
    // Should be set to false in production
    'display_error_details' => false,
    // Parameter is passed to the default ErrorHandler
    // View in rendered output by enabling the "displayErrorDetails" setting.
    // For the console and unit tests it should be disabled too
    'log_errors' => true,
    // Display error details in error log
    'log_error_details' => true,
];

// Logger settings
$settings['logger'] = [
    'name'        => 'totalcms',
    'path'        => __DIR__ . '/../logs',
    'filename'    => 'totalcms.log',
    'level'       => Monolog\Level::Info,
    'maxFiles'    => 30,
    'permissions' => 0775,
];

// Session
$settings['session'] = [
    'name'         => 'totalcms',
    'cache_expire' => 0,
];

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

$settings['imageworks'] = [
    'watermarksGallery' => 'watermarks',
    'defaults'          => [
        'fm' => 'jpg',
        'q'  => 92,
    ],
    'presets' => [
        'small' => [
            'w'   => 200,
            'h'   => 200,
            'fit' => 'crop',
        ],
        'medium' => [
            'w'   => 600,
            'h'   => 400,
            'fit' => 'crop',
        ],
    ],
];

// Console commands
// $settings['commands'] = [
//     \App\Console\SchemaDumpCommand::class,
// ];

return $settings;
