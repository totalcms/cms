<?php
// Sample configuration file for /tcms.php

$settings['sentry']['enable'] = true;

$settings['api']     = '/site-assets/stacks/ws.tcms.core/tcms/';
$settings['datadir'] = $settings['docroot'] . '/tcms-data';

// https://www.php.net/manual/en/timezones.php
$settings['timezone'] = 'America/Denver';

$settings['imageworks']['watermarksGallery'] = 'watermarks';
$settings['imageworks']['presets'] = [
	'small' => [
		'w'   => 300,
		'h'   => 200,
	],
	'small-crop' => [
		'w'   => 300,
		'h'   => 300,
		'fit' => 'crop-focalpoint',
	],
	'medium' => [
		'w'   => 600,
		'h'   => 400,
	],
	'medium-crop' => [
		'w'   => 600,
		'h'   => 600,
		'fit' => 'crop-focalpoint',
	],
];
