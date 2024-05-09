<?php

use DI\Container;
use Slim\App;

/* Workaround for routes with a dot in local php server */
if (php_sapi_name() == 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = basename($_SERVER['SCRIPT_FILENAME']);
    $file                   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if (file_exists(__DIR__ . $file)) {
        /* Return contents of the static file. */
        return false;
    }

    if (str_contains($_SERVER['DOCUMENT_ROOT'], 'RapidWeaver') || str_contains($_SERVER['DOCUMENT_ROOT'], 'Stacks')) {
        // Stacks Internal PHP Preview server
        $_SERVER['APP_ENV'] = 'preview';
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

// $containerBuilder = new ContainerBuilder();
// $containerBuilder->addDefinitions(__DIR__ . '/container.php');
// $container = $containerBuilder->build();

$container = new Container(require __DIR__ . '/container.php');

// Create App instance
$app = $container->get(App::class);

// Register middleware
(require __DIR__ . '/middleware.php')($app);

// Register routes
(require __DIR__ . '/routes.php')($app);

return $app;
