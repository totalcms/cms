<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Extension\ExtensionAssetAction;
use TotalCMS\Action\Extension\ExtensionRouteAction;

return function (RouteCollectorProxyInterface $app): void {
	// Extension static assets (no auth)
	$app->get('/ext/{vendor}/{name}/assets/{file:.+}', ExtensionAssetAction::class);

	// Extension API and public routes (auth checked in handler)
	$app->any('/ext/{vendor}/{name}/{path:.+}', ExtensionRouteAction::class);
};
