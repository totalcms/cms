<?php

declare(strict_types=1);

// error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
// error_reporting(E_ALL);
// ini_set('display_errors', '1');

// echo "TEST Environment\n";

// Continuous integration environment
$settings['env'] = 'test';

$settings['root']     = dirname(__DIR__);
$settings['docroot']  = $settings['root'];
$settings['datadir']  = $settings['root'] . '/tests/tcms-data';
$settings['cachedir'] = $settings['root'] . '/cache';
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
$settings['sentry']           = false;
$settings['auth']['enable']   = false;

// $settings['cache'] = [
// 	'filesystem' =>  false,
// 	'redis' =>  false,
// 	'memcached' =>  false,
// ];
