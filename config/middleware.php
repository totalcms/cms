<?php

declare(strict_types=1);

use Middlewares\TrailingSlash;
use Odan\Session\Middleware\SessionStartMiddleware;
use Selective\Validation\Middleware\ValidationExceptionMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\MethodOverrideMiddleware;
use TotalCMS\Middleware\BasePathMiddleware;
use TotalCMS\Middleware\CacheInvalidationMiddleware;
use TotalCMS\Middleware\Development\DevModeMiddleware;
use TotalCMS\Middleware\Development\SentryMiddleware;
use TotalCMS\Middleware\License\BundleMiddleware;
use TotalCMS\Middleware\License\LicenseValidationMiddleware;
use TotalCMS\Middleware\MaintenanceModeMiddleware;
use TotalCMS\Middleware\PageRouterMiddleware;
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
	$app->add(MaintenanceModeMiddleware::class);
	$app->add(RobotsTagMiddleware::class);
	$app->add(LicenseValidationMiddleware::class);
	$app->add(ValidationExceptionMiddleware::class);
	$app->add(NoCacheErrorMiddleware::class);
	$app->addRoutingMiddleware();
	// SetupCheckMiddleware sits OUTSIDE RoutingMiddleware so it can intercept
	// requests for unrouted paths (e.g. `/`) — otherwise Slim would throw a
	// 404 before this middleware ever ran, and a fresh install would never
	// see the setup wizard. The middleware uses URL-prefix checks because
	// the route context isn't populated at this point in the chain.
	$app->add(SetupCheckMiddleware::class);
	$app->add(BasePathMiddleware::class);
	$app->add(SentryMiddleware::class);
	$app->add(ErrorMiddleware::class);
	$app->add(TrailingSlash::class);
	$app->add(MethodOverrideMiddleware::class);

	if (TotalCMS::isPreview()) {
		$app->add(PreviewRouteMiddleware::class);
	}

	// Page router wraps everything — catches 404s from Slim and tries builder pages.
	$app->add(PageRouterMiddleware::class);

	// Session must wrap PageRouter so the session is still open when
	// PageRouter does its post-Slim work (matching builder pages, running
	// per-page middleware like `auth`, rendering templates that read session
	// state). If SessionStartMiddleware were registered earlier, save() would
	// close the session before PageRouter got control back, and auth checks
	// would see an empty session — which is what caused the /admin → builder
	// page redirect loop.
	$app->add(SessionStartMiddleware::class);
};
