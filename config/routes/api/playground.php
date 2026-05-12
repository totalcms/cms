<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Playground;
use TotalCMS\Middleware\Access\PlaygroundAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/playground', function (RouteCollectorProxy $group): void {
		$group->get('', Playground\PlaygroundListAction::class)->setName('playground-list');
		$group->post('', Playground\PlaygroundSaveAction::class)->setName('playground-save');
		$group->get('/{id}', Playground\PlaygroundFetchAction::class)->setName('playground-fetch');
		$group->put('/{id}', Playground\PlaygroundUpdateAction::class)->setName('playground-update');
		$group->delete('/{id}', Playground\PlaygroundDeleteAction::class)->setName('playground-delete');
	})->add(PlaygroundAccessMiddleware::class)
		->add(AuthMiddleware::class);
};
