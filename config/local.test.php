<?php

// error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
// error_reporting(E_ALL);
// ini_set('display_errors', '1');

// echo "TEST Environment\n";

// Continuous integration environment
$settings['env'] = 'test';

$settings['root']     = dirname(__DIR__);
$settings['docroot']  = $settings['root'];
$settings['datadir']  = $settings['root'] . '/tests/tcms-data';
$settings['cachedir'] = 'false';
$settings['domain']   = 'totalcms.test';

$settings['error']['display_error_details'] = true;
$settings['error']['log_errors']            = true;

$settings['logger']['level']  = Monolog\Level::Debug;
$settings['sentry']['enable'] = false;
$settings['session']['enable'] = false;
