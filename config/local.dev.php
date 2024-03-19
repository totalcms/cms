<?php

// Development Environment
// echo "DEV Environment\n";

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
// error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['env'] = 'dev';

$settings['docroot']  = $settings['root'];
$settings['datadir']  = $settings['root'] . '/tcms-data';
$settings['cachedir'] = 'false';
$settings['domain']   = 'totalcms.test';
$settings['url']      = 'https://totalcms.test';
$settings['api']      = 'https://totalcms.test';

$settings['error']['display_error_details'] = true;
$settings['error']['log_errors']            = true;

$settings['logger']['level']  = Monolog\Level::Debug;
$settings['assets']['minify'] = 0;
$settings['locale']['cache']  = null;
