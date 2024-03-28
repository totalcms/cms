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
$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if ($environment) {
    $envSettings = __DIR__ . '/local.' . strtolower($environment) . '.php';
    if (file_exists($envSettings)) {
        require $envSettings;
    }
}

// print_r($settings);

return $settings;
