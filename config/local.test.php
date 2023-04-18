<?php

// Continuous integration environment
$settings['env'] = 'test';

$settings['error']['display_error_details'] = true;
$settings['error']['log_errors']            = false;

$settings['logger']['level']  = \Monolog\Level::Debug;
$settings['assets']['minify'] = 0;
$settings['locale']['cache']  = null;
