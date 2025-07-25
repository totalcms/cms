<?php

// Configure defaults for the whole application.

// Error reporting
error_reporting(0);
ini_set('display_errors', '0');

if (isset($_GET['debugstart'])) {
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
}

// locale
setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'en_US');

// JSON fix for saving float values
ini_set('serialize_precision', '-1');

// Cloudflare IP address header
if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
}

// Settings
$settings = [];

$settings['sentry'] = [
	'enable' => true,
	'init'   => [
		'dsn' => 'p16xTYgwpMx9Z9UBsuOuqV7N7v9NgKpf_3RN7XSvTAiFs3OQXJcSlY5n4IGK-4dbKnAhOvY59eZujBuqmIJN7kAlximb86OwSyrMs9lzODhTfr6jMGXQp2Vs1fLlHRY',
		// Specify a fixed sample rate
		'traces_sample_rate' => 1.0,
		// Set a sampling rate for profiling - this is relative to traces_sample_rate
		'profiles_sample_rate' => 1.0,
		'ignore_exceptions'    => [
			Slim\Exception\HttpNotFoundException::class,
			Slim\Exception\HttpMethodNotAllowedException::class,
		],
	],
];

// Default env to production
$settings['env']    = 'prod';
$settings['locale'] = 'en_US';

$settings['domain']   = $_SERVER['HTTP_HOST'] ?? 'unknown';
$settings['url']      = 'https://' . $settings['domain'];
$settings['api']      = $settings['url'] . '/api';
$settings['notfound'] = '/404';

// Path settings
$settings['root']     = dirname(__DIR__);
$settings['tmpdir']   = $settings['root'] . '/tmp';
$settings['public']   = $settings['root'] . '/public';
$settings['template'] = $settings['root'] . '/resources/templates';
$settings['schemas']  = $settings['root'] . '/resources/schemas';

$settings['debug'] = false; // Set to true for development

// Cache configuration
$settings['cache'] = [
	'filesystem' => [
		'enabled'   => true,
		'directory' => $settings['root'] . '/cache',
	],
	'redis' => [
		'enabled'  => true,
		'host'     => '127.0.0.1',
		'port'     => 6379,
		'timeout'  => 1,
		'password' => null,
		'database' => 0,
	],
	'memcached' => [
		'enabled' => true,
		'host'    => '127.0.0.1',
		'port'    => 11211,
	],
];

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
	'maxFiles'    => 10,
	'permissions' => 0775,
];

// Session
$settings['session'] = [
	'name'                   => null, // Setting this to null for conflict to other stacks. Otherwise use 'totalcms',
	'cookie_samesite'        => 'Lax',
	'cache_expire'           => 0,
	'cookie_secure'          => true,
	'cookie_httponly'        => true,
	'cookie_lifetime'        => 0,
	'gc_maxlifetime'         => 7200,
	'use_trans_sid'          => false,
	'use_only_cookies'       => true,
	// 'sid_length'             => 64,
	// 'sid_bits_per_character' => 6,
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
	'watermarkFontsDepot' => 'watermark-fonts',
	'defaults'          => [
		'fm' => 'jpg',
		'q'  => 92,
	],
	'presets' => [
		'small' => [
			'w'   => 300,
			'h'   => 200,
		],
		'small-crop' => [
			'w'   => 300,
			'h'   => 300,
			'fit' => 'crop-focalpoint',
		],
		'medium' => [
			'w'   => 600,
			'h'   => 400,
		],
		'medium-crop' => [
			'w'   => 600,
			'h'   => 600,
			'fit' => 'crop-focalpoint',
		],
	],
];

$settings['auth'] = [
	'enable'                => true,
	'collection'            => 'auth',
	'maxAttempts'           => 10,
	'deniedTimeout'         => 7,
	'deniedDefaultRedirect' => '/',
];

$settings['htmlclean'] = [
	'enabled'                => true,  // Set to false to disable HTML sanitization globally
	'allowed_css_properties' => [
		'color',
		'background-color',
		'font-size',
		'font-weight',
		'font-style',
		'font-family',
		'text-align',
		'text-decoration',
		'margin',
		'margin-left',
		'margin-right',
		'margin-top',
		'margin-bottom',
		'padding',
		'padding-left',
		'padding-right',
		'padding-top',
		'padding-bottom',
		'border',
		'line-height',
		'list-style-type',
		'width',
		'height',
		'max-width',
		'max-height',
		'display',
	],
	// 'allowed_tags' => ['p', 'strong', 'em'],
	// 'allowed_iframe_domains' => ['www.youtube.com']
];

$settings['dashboard'] = [
	'pagination' => 50, // Default pagination for dashboard tables
];

// https://www.php.net/manual/en/timezones.php
// DateTimeZone::listIdentifiers()
$settings['timezone'] = date_default_timezone_get();

return $settings;
