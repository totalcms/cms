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

if (!class_exists(TotalCMS\Support\PathResolver::class, false)) {
	require_once __DIR__ . '/../vendor/autoload.php';
}

// Define ROOT for CakePHP I18n translations (resources/locales/)
if (!defined('ROOT')) {
	define('ROOT', TotalCMS\Support\PathResolver::packageRoot());
}

$container = new Container(require __DIR__ . '/container.php');

// Sentry Logger
$sentryEnabled = $container->get(Config::class)->sentry;
if ($sentryEnabled === true) {
	TotalCMS\Middleware\Development\SentryMiddleware::initSentry();
}

// Create App instance
$app = $container->get(App::class);

// Discover and register extensions (before middleware/routes so extensions can add container definitions)
$extensionManager = $container->get(TotalCMS\Domain\Extension\Service\ExtensionManager::class);
$extensionManager->discoverAndRegister();

// Register middleware
(require __DIR__ . '/middleware.php')($app);

// Register routes
(require __DIR__ . '/routes.php')($app);

// Boot extensions (register Twig items, schemas, events, etc.)
$extensionManager->bootAll();

return $app;
