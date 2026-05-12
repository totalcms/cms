<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\ApiKey\ApiKeyCreateAction;
use TotalCMS\Action\Admin\ApiKey\ApiKeyDeleteAction;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\License\ApiKeysEditionMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// API endpoints for managing API keys (requires super admin + Pro edition)
	$app->group('/apikeys', function (RouteCollectorProxy $group): void {
		$group->post('', ApiKeyCreateAction::class)->setName('apikey-create');
		$group->delete('/{id}', ApiKeyDeleteAction::class)->setName('apikey-delete');
	})->add(ApiKeysEditionMiddleware::class)
		->add(AdminOnlyMiddleware::class)
		->add(AuthMiddleware::class);
};
