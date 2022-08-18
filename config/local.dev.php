<?php

// Development Environment
// echo "DEV Environment\n";

error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['env'] = 'dev';

$settings['docroot'] = $settings['root'];
$settings['datadir'] = $settings['root'] . '/tcms-data';

$settings['error_handler_middleware']['display_error_details'] = true;
$settings['error_handler_middleware']['log_errors']            = true;

$settings['logger']['level']  = \Monolog\Logger::DEBUG;
$settings['assets']['minify'] = 0;
$settings['locale']['cache']  = null;

// Database
// $settings['db']['database'] = 'dynamics_dev';
