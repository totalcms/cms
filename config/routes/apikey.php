<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\ApiKey\ApiKeyCreateAction;
use TotalCMS\Action\Admin\ApiKey\ApiKeyDeleteAction;
use TotalCMS\Middleware\AdminOnlyMiddleware;
use TotalCMS\Middleware\AuthMiddleware;

return function (App $app): void {
	// API endpoints for managing API keys (requires super admin)
	$app->group('/apikeys', function (RouteCollectorProxy $group): void {
		$group->post('', ApiKeyCreateAction::class)->setName('apikey-create');
		$group->delete('/{id}', ApiKeyDeleteAction::class)->setName('apikey-delete');
	})->add(AuthMiddleware::class)
		->add(AdminOnlyMiddleware::class);
};
