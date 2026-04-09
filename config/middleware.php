<?php

use Middlewares\TrailingSlash;
use Odan\Session\Middleware\SessionStartMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\MethodOverrideMiddleware;
use TotalCMS\Middleware\CacheInvalidationMiddleware;
use TotalCMS\Middleware\Development\DevModeMiddleware;
use TotalCMS\Middleware\Development\SentryMiddleware;
use TotalCMS\Middleware\License\BundleMiddleware;
use TotalCMS\Middleware\License\LicenseValidationMiddleware;
use TotalCMS\Middleware\MaintenanceModeMiddleware;
use TotalCMS\Middleware\Response\NoCacheErrorMiddleware;
use TotalCMS\Middleware\Response\PreviewRouteMiddleware;
use TotalCMS\Middleware\Response\RobotsTagMiddleware;
use TotalCMS\Middleware\SetupCheckMiddleware;
use TotalCMS\TotalCMS;

return function (App $app): void {
	$app->addBodyParsingMiddleware();
	$app->add(DevModeMiddleware::class);
	$app->add(CacheInvalidationMiddleware::class);
	$app->add(BundleMiddleware::class);
	$app->add(SessionStartMiddleware::class);
	$app->add(MaintenanceModeMiddleware::class);
	$app->add(SetupCheckMiddleware::class);
	$app->add(RobotsTagMiddleware::class);
	$app->add(LicenseValidationMiddleware::class);
	$app->add(ValidationExceptionMiddleware::class);
	$app->add(NoCacheErrorMiddleware::class);
	$app->addRoutingMiddleware();
	$app->add(BasePathMiddleware::class);
	$app->add(SentryMiddleware::class);
	$app->add(ErrorMiddleware::class);
	$app->add(TrailingSlash::class);
	$app->add(MethodOverrideMiddleware::class);

	if (TotalCMS::isPreview()) {
		$app->add(PreviewRouteMiddleware::class);
	}
};
