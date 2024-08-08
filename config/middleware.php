<?php

use Middlewares\TrailingSlash;
use Selective\BasePath\BasePathMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\MethodOverrideMiddleware;
use TotalCMS\Middleware\CorsMiddleware;
use TotalCMS\Middleware\LiteLicenseMiddleware;
use TotalCMS\Middleware\PreviewRouteMiddleware;
use TotalCMS\Middleware\SentryMiddleware;
use TotalCMS\Middleware\BetaMiddleware;

return function (App $app) {
	$app->addBodyParsingMiddleware();
	$app->add(BetaMiddleware::class);
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
	if ($environment === 'preview') {
		$app->add(PreviewRouteMiddleware::class);
	}
};
