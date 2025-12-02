<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\ApiKey\ApiKeyCreateAction;
use TotalCMS\Action\Admin\ApiKey\ApiKeyDeleteAction;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\License\EditionFeatureMiddleware;

return function (App $app): void {
	$container = $app->getContainer();
	if ($container === null) {
		throw new \RuntimeException('Container not available');
	}

	// API endpoints for managing API keys (requires super admin + Pro edition)
	$app->group('/apikeys', function (RouteCollectorProxy $group): void {
		$group->post('', ApiKeyCreateAction::class)->setName('apikey-create');
		$group->delete('/{id}', ApiKeyDeleteAction::class)->setName('apikey-delete');
	})->add(new EditionFeatureMiddleware(
		$container->get(EditionFeatureService::class),
		EditionFeature::API_KEYS
	))
		->add(AdminOnlyMiddleware::class)
		->add(AuthMiddleware::class);
};
