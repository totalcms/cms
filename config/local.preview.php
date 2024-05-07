<?php

// Preview Environment
// echo "Preview Environment\n";

error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['env'] = 'preview';

$settings['docroot']   = $settings['root'];
$settings['datadir']   = $settings['root'] . '/tcms-data';
$settings['cachedir']  = 'false';
$settings['api']       = $_SERVER['TCMS_API'] ?: '/rw_common/plugins/stacks/tcms/';

$settings['error']['display_error_details'] = false;
$settings['error']['log_errors']            = true;
$settings['error']['log_error_details']     = true;

$settings['logger']['level']  = Monolog\Level::Debug;
$settings['assets']['minify'] = 0;
// $settings['locale']['cache']  = null;
$settings['logger']['path'] = $settings['datadir'] . '/logs';
