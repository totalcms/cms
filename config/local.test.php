<?php

declare(strict_types=1);

// error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
// error_reporting(E_ALL);
// ini_set('display_errors', '1');

// echo "TEST Environment\n";

// Continuous integration environment
$settings['env'] = 'test';

$settings['root']     = dirname(__DIR__);
// Per-worker sandbox when running under `pest --parallel` (paratest sets
// TEST_TOKEN); plain paths for a serial run. Published by tests/bootstrap.php
// — see tests/worker-paths.php. OAuth key paths below derive from datadir, so
// they follow automatically.
$settings['datadir']  = $_SERVER['TCMS_TEST_DATADIR']  ?? ($settings['root'] . '/tests/tcms-data');
$settings['cachedir'] = $_SERVER['TCMS_TEST_CACHEDIR'] ?? ($settings['root'] . '/cache');
$settings['tmpdir']   = $_SERVER['TCMS_TEST_TMPDIR']   ?? ($settings['root'] . '/tmp');
$settings['domain']   = 'totalcms.test';

// OAuth signing-key paths are computed in defaults.php using $settings['datadir']
// AT DEFAULT-LOAD TIME — which points at the live tcms-data, not the test one.
// Re-derive them against the test datadir so tests don't accidentally read or
// (worse) regenerate the dev environment's keys.
$settings['oauth']['signingKeyPath'] = $settings['datadir'] . '/.system/oauth-keys/private.key';
$settings['oauth']['publicKeyPath']  = $settings['datadir'] . '/.system/oauth-keys/public.key';

$settings['error']['display_error_details'] = true;
$settings['error']['log_errors']            = true;

$settings['logger']['level']  = Monolog\Level::Debug;
$settings['logger']['path']   = $settings['root'] . '/logs';
$settings['sentry']           = false;
$settings['auth']['enable']   = false;

// $settings['cache'] = [
// 	'filesystem' =>  false,
// 	'redis' =>  false,
// 	'memcached' =>  false,
// ];
