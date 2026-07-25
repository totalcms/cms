<?php

use Slim\App;
use TotalCMS\Support\Config;
use TotalCMS\Support\ContainerFactory;

// PHP built-in dev server (router-script mode, e.g.
// `php -S localhost:8080 -t public public/index.php`): serve existing static
// files directly and let everything else fall through to Slim. The check runs
// against the docroot with is_file() — file_exists() matched directories, so
// `/` resolved to a directory and returned false, which the entry point then
// tried to ->run(). Entry points must propagate this `false` to the server
// instead of calling ->run() on it.
if (php_sapi_name() == 'cli-server') {
	$_SERVER['SCRIPT_NAME'] = basename((string)$_SERVER['SCRIPT_FILENAME']);
	$file                   = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$docroot                = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
	if ($docroot !== '' && $file !== '' && is_file($docroot . $file)) {
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

$container = ContainerFactory::build();

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
