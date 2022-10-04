<?php

use DI\ContainerBuilder;
use Slim\App;

/* Workaround for routes with a dot in local php server */
if (php_sapi_name() == 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = basename($_SERVER['SCRIPT_FILENAME']);

    $file = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (file_exists(__DIR__ . $file)) {
        /* Return contents of the static file. */
        return false;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

// Set up settings
$containerBuilder->addDefinitions(__DIR__ . '/container.php');

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Create App instance
$app = $container->get(App::class);

// Register routes
(require __DIR__ . '/routes.php')($app);

// Register middleware
(require __DIR__ . '/middleware.php')($app);

return $app;
