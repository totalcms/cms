<?php

use Slim\App;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
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

// Extension bootstrap is the one place where third-party code runs before the
// app can serve anything, so both phases get a hard net around them. The manager
// already guards each extension individually; this catches whatever a future gap
// (or a broken state file) lets escape, so the worst case is "extensions are
// unavailable" rather than a blank 500 on every route including the admin page
// the operator needs to go and disable the offending extension.
$bootExtensions = static function (callable $phase, string $label) use ($container): void {
    try {
        $phase();
    } catch (Throwable $e) {
        $container->get(LoggerFactory::class)
            ->channelLogger(LogChannel::Extensions)
            ->critical("Extension {$label} failed; extensions are degraded: " . $e->getMessage(), ['exception' => $e]);
    }
};

// Discover and register extensions (before middleware/routes so extensions can add container definitions)
$extensionManager = $container->get(TotalCMS\Domain\Extension\Service\ExtensionManager::class);
$bootExtensions(static fn () => $extensionManager->discoverAndRegister(), 'discoverAndRegister()');

// Register middleware
(require __DIR__ . '/middleware.php')($app);

// Register routes
(require __DIR__ . '/routes.php')($app);

// Boot extensions (register Twig items, schemas, events, etc.)
$bootExtensions(static fn () => $extensionManager->bootAll(), 'bootAll()');

return $app;
