<?php

// Development Environment
$settings['env'] = 'development';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['docroot'] = $settings['root'];
$settings['data'] = $settings['root'] . '/tcms-data';

$settings['error_handler_middleware']['display_error_details'] = true;
$settings['error_handler_middleware']['log_errors'] = true;

$settings['logger'] = [
    'name' => 'totalcms',
    'path' => $settings['root'] . '/logs',
    'filename' => 'totalcms.log',
    'level' => \Monolog\Logger::DEBUG,
    'file_permission' => 0775,
];

$settings['logger']['path'] = $settings['root'] . '/logs';
$settings['logger']['level'] = \Monolog\Logger::DEBUG;
$settings['assets']['minify'] = 0;
$settings['locale']['cache'] = null;
$settings['twig']['options']['cache_enabled'] = false;

// Database
$settings['db']['database'] = 'dynamics_dev';
