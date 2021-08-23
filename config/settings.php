<?php

// Defaults
$settings = require __DIR__ . '/defaults.php';

// Overwrite default settings with environment specific local settings
if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/env.php')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/env.php';
} elseif (file_exists(__DIR__ . '/env.php')) {
    require __DIR__ . '/env.php';
}

// Unit-test and integration environment (Travis CI)
$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '';
if ($environment) {
    require __DIR__ . '/local.' . $environment . '.php';
}

return $settings;
