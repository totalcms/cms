<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Action\Extension\ExtensionAdminRouteAction;
use TotalCMS\Action\Extension\ExtensionAssetAction;
use TotalCMS\Action\Extension\ExtensionRouteAction;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Cache\VersionCheckMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

return function (App $app): void {
	// Extension static assets (no auth)
	$app->get('/ext/{vendor}/{name}/assets/{file:.+}', ExtensionAssetAction::class);

	// Extension API and public routes (auth checked in handler)
	$app->any('/ext/{vendor}/{name}/{path:.+}', ExtensionRouteAction::class);

	// Extension admin pages (full admin auth via middleware)
	$app->any('/admin/ext/{vendor}/{name}/{path:.+}', ExtensionAdminRouteAction::class)
		->add(VersionCheckMiddleware::class)
		->add(AuthMiddleware::class)
		->add(NoCacheMiddleware::class);
};
