<?php

use DI\Container;
use Slim\App;
use TotalCMS\Support\Config;

// Workaround for routes with a dot in local php server
if (php_sapi_name() == 'cli-server') {
	$_SERVER['SCRIPT_NAME'] = basename((string)$_SERVER['SCRIPT_FILENAME']);
	$file                   = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
	if (file_exists(__DIR__ . $file)) {
		/* Return contents of the static file. */
		return false;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

// $containerBuilder = new ContainerBuilder();
// $containerBuilder->addDefinitions(__DIR__ . '/container.php');
// $container = $containerBuilder->build();

$container = new Container(require __DIR__ . '/container.php');

// Sentry Logger
$sentryEnabled = $container->get(Config::class)->sentry;
if ($sentryEnabled === true) {
	TotalCMS\Middleware\Development\SentryMiddleware::initSentry();
}

// Create App instance
$app = $container->get(App::class);

// Register middleware
(require __DIR__ . '/middleware.php')($app);

// Register routes
(require __DIR__ . '/routes.php')($app);

return $app;
