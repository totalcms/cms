<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\DataView;
use TotalCMS\Middleware\Access\DataViewsAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Middleware\License\DataViewsEditionMiddleware;
use TotalCMS\Middleware\Security\ExternalCorsMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/dataviews', function (RouteCollectorProxy $group): void {
		$group->post('/test', DataView\DataViewTestAction::class)->setName('dataview-test');
		$group->post('/test/html', DataView\DataViewTestHtmlAction::class)->setName('dataview-test-html');
		$group->post('/{id}/rebuild', DataView\DataViewRebuildAction::class)->setName('dataview-rebuild');
	})->add(DataViewsEditionMiddleware::class)
		->add(DataViewsAccessMiddleware::class)
		->add(AuthMiddleware::class);

	$app->group('/dataviews', function (RouteCollectorProxy $group): void {
		$group->get('/{id}/data', DataView\DataViewFetchAction::class)->setName('dataview-fetch');
		$group->get('/{id}/query', DataView\DataViewQueryAction::class)->setName('dataview-query');
	})->add(DataViewsEditionMiddleware::class)
		->add(DataViewsAccessMiddleware::class)
		->add(DualAuthMiddleware::class)
		->add(ExternalCorsMiddleware::class);
};
