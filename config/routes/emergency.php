<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Emergency\EmergencyCacheClearAction;
use TotalCMS\Action\Emergency\EmergencyLicenseCacheClearAction;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

return function (App $app): void {
	// Emergency endpoints - bypass normal authentication
	// Only accessible from localhost with emergency key for security
	$app->group('/emergency', function (RouteCollectorProxy $group): void {
		$group->get('/cache/clear', EmergencyCacheClearAction::class)->setName('emergency-cache-clear');
		$group->get('/cache/clear-license', EmergencyLicenseCacheClearAction::class)->setName('emergency-license-cache-clear');
	})->add(NoCacheMiddleware::class);
};
