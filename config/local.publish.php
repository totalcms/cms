<?php

// error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Publish Environment
$settings['env']                            = 'publish';
$settings['api']                            = $_SERVER['TCMS_API'] ?: '/rw_common/plugins/stacks/tcms/';
$settings['logger']['path']                 = $settings['datadir'] . '/logs';
$settings['error']['display_error_details'] = false;
$settings['error']['log_errors']            = true;
$settings['error']['log_error_details']     = true;
