<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Playground;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Access\PlaygroundAccessMiddleware;

return function (App $app): void {
	$app->group('/playground', function (RouteCollectorProxy $group): void {
		$group->get('', Playground\PlaygroundListAction::class)->setName('playground-list');
		$group->post('', Playground\PlaygroundSaveAction::class)->setName('playground-save');
		$group->get('/{id}', Playground\PlaygroundFetchAction::class)->setName('playground-fetch');
		$group->put('/{id}', Playground\PlaygroundUpdateAction::class)->setName('playground-update');
		$group->delete('/{id}', Playground\PlaygroundDeleteAction::class)->setName('playground-delete');
	})->add(PlaygroundAccessMiddleware::class)
		->add(AuthMiddleware::class);
};
