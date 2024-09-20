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
use TotalCMS\Middleware\RobotsTagMiddleware;
use TotalCMS\Middleware\SentryMiddleware;

return function (App $app) {
	// Stacks internal PHP server
	$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
	$preview = ($environment === 'preview' || PHP_SAPI === 'cli-server');

	$app->addBodyParsingMiddleware();
	$app->add(BetaMiddleware::class);
	$app->add(BundleMiddleware::class);
	if (!$preview) {
		$app->add(SessionStartMiddleware::class);
	}
	$app->add(CorsMiddleware::class);
	$app->add(RobotsTagMiddleware::class);
	$app->add(LiteLicenseMiddleware::class);
	$app->add(ValidationExceptionMiddleware::class);
	$app->addRoutingMiddleware();
	$app->add(BasePathMiddleware::class);
	$app->add(SentryMiddleware::class);
	$app->add(ErrorMiddleware::class);
	$app->add(TrailingSlash::class);
	$app->add(MethodOverrideMiddleware::class);

	if ($preview) {
		$app->add(PreviewRouteMiddleware::class);
	}
};
