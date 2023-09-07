<?php

// error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// echo "TEST Environment\n";

// Continuous integration environment
$settings['env'] = 'test';

$settings['docroot']  = $settings['root'];
$settings['datadir']  = $settings['root'] . '/tests/tcms-data';
$settings['cachedir'] = 'false';

$settings['error']['display_error_details'] = true;
$settings['error']['log_errors']            = true;
