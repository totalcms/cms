<?php

// Defaults
$settings = require __DIR__ . '/defaults.php';

// load in optional settings from dynamics root
if (file_exists(__DIR__ . '/../env.php')) {
    require __DIR__ . '/../env.php';
}

// Load environment configuration
if (file_exists(__DIR__ . '/' . $settings['env'] . '.php')) {
    require __DIR__ . '/' . $settings['env'] . '.php';
}

// load in optional settings from doc root
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/env.php')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/env.php';
}

// Unit-test and integration environment (Travis CI)
// if (defined('APP_ENV')) {
//     require __DIR__.'/'.basename(APP_ENV).'.php';
// }

return $settings;
