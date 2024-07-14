<?php

// Stacks Preview Environment
// echo "Stacks Preview Environment\n";

error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['env'] = 'preview';

$settings['sentry']['enable'] = false;

$settings['datadir']   = sys_get_temp_dir() . '/tcms-data';
if (isset($_SERVER['DOMAIN']) && is_string($_SERVER['DOMAIN'])) {
	$settings['datadir'] .= '-' . $_SERVER['DOMAIN'];
}
if (isset($_SERVER['PREVIEW_TCMSDIR']) && is_string($_SERVER['PREVIEW_TCMSDIR'])) {
	// this means that a datadir is passed via the API
	// PreviewRouteMiddleware adds it to $_SERVER['PREVIEW_TCMSDIR']
	$settings['datadir'] = $_SERVER['PREVIEW_TCMSDIR'];
}
if (isset($_GET['datadir']) && is_string($_GET['datadir'])) {
	// Get the datadir from the query string
	$settings['datadir'] = $_GET['datadir'];
}
$settings['docroot']   = $settings['root'];
$settings['cachedir']  = 'false';
$settings['api']       = sprintf('/rw_common/plugins/stacks/tcms/public/?datadir=%s&route=', $settings['datadir']);

$settings['error']['display_error_details'] = false;
$settings['error']['log_errors']            = true;
$settings['error']['log_error_details']     = true;

$settings['logger']['level']  = Monolog\Level::Debug;
$settings['assets']['minify'] = 0;
// $settings['locale']['cache']  = null;
$settings['logger']['path'] = $settings['datadir'] . '/logs';
