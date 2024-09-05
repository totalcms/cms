<?php

use Middlewares\TrailingSlash;
use Odan\Session\Middleware\SessionStartMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\MethodOverrideMiddleware;
use TotalCMS\Middleware\BetaMiddleware;
use TotalCMS\Middleware\BundleMiddleware;
use TotalCMS\Middleware\CorsMiddleware;
use TotalCMS\Middleware\LiteLicenseMiddleware;
use TotalCMS\Middleware\PreviewRouteMiddleware;
use TotalCMS\Middleware\SentryMiddleware;

return function (App $app) {
	$app->addBodyParsingMiddleware();
	$app->add(BetaMiddleware::class);
	$app->add(BundleMiddleware::class);
	$app->add(SessionStartMiddleware::class);
	$app->add(CorsMiddleware::class);
	$app->add(LiteLicenseMiddleware::class);
	$app->add(ValidationExceptionMiddleware::class);
	$app->addRoutingMiddleware();
	$app->add(BasePathMiddleware::class);
	$app->add(SentryMiddleware::class);
	$app->add(ErrorMiddleware::class);
	$app->add(TrailingSlash::class);
	$app->add(MethodOverrideMiddleware::class);

	// Stacks internal PHP server
	$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
	if ($environment === 'preview' || PHP_SAPI === 'cli-server') {
		$app->add(PreviewRouteMiddleware::class);
	}
};
