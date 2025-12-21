<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Setup;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

return function (App $app): void {
	// Data path setup (runs before authentication)
	$app->group('/setup', function (RouteCollectorProxy $group): void {
		$group->get('/data-path', Setup\DataPathSetupAction::class)->setName('setup-data-path');
		$group->post('/data-path', Setup\DataPathSetupSubmitAction::class)->setName('setup-data-path-submit');
	})->add(NoCacheMiddleware::class);
};
