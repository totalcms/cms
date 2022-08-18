<?php

// Continuous integration environment
$settings['env'] = 'test';

$settings['error_handler_middleware']['display_error_details'] = true;
$settings['error_handler_middleware']['log_errors']            = false;

$settings['logger']['level']  = \Monolog\Logger::DEBUG;
$settings['assets']['minify'] = 0;
$settings['locale']['cache']  = null;
