<?php

// Development Environment

error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['env'] = 'dev';

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

// Database
$settings['db']['database'] = 'dynamics_dev';
