<?php

// Stacks Preview Environment
// echo "Stacks Preview Environment\n";

error_reporting(E_ALL);
ini_set('display_errors', '1');

$settings['env'] = 'preview';

// This file can exist in order to set a preview domain for the preview environment
$previewDomainFile = __DIR__ . '/preview-domain';

$settings['datadir'] = sys_get_temp_dir() . '/tcms-data';
if (isset($_SERVER['DOMAIN']) && is_string($_SERVER['DOMAIN'])) {
	$settings['datadir'] .= '-' . $_SERVER['DOMAIN'];
} elseif (file_exists($previewDomainFile)) {
	$settings['datadir'] .= '-' . file_get_contents($previewDomainFile);
}

$settings['docroot']   = $settings['root'];
$settings['cachedir']  = 'false';
$settings['api']       = '/rw_common/plugins/stacks/tcms/public/index.php';

$settings['error']['display_error_details'] = false;
$settings['error']['log_errors']            = true;
$settings['error']['log_error_details']     = true;

$settings['logger']['level']  = Monolog\Level::Debug;
$settings['assets']['minify'] = 0;
// $settings['locale']['cache']  = null;
$settings['logger']['path'] = $settings['datadir'] . '/logs';
$settings['sentry']['enable'] = false;
