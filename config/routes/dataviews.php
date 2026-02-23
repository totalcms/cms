<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\DataView;
use TotalCMS\Middleware\Access\DataViewsAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Middleware\License\DataViewsEditionMiddleware;

return function (App $app): void {
	$app->group('/dataviews', function (RouteCollectorProxy $group): void {
		$group->post('/test', DataView\DataViewTestAction::class)->setName('dataview-test');
		$group->post('/{id}/rebuild', DataView\DataViewRebuildAction::class)->setName('dataview-rebuild');
	})->add(DataViewsEditionMiddleware::class)
		->add(DataViewsAccessMiddleware::class)
		->add(AuthMiddleware::class);

	$app->group('/dataviews', function (RouteCollectorProxy $group): void {
		$group->get('/{id}/data', DataView\DataViewFetchAction::class)->setName('dataview-fetch');
	})->add(DataViewsEditionMiddleware::class)
		->add(DataViewsAccessMiddleware::class)
		->add(DualAuthMiddleware::class);
};
