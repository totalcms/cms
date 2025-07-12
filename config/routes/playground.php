<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Playground;

return function (App $app) {
	$app->group('/playground', function (RouteCollectorProxy $group) {
		$group->get('', Playground\PlaygroundListAction::class)->setName('playground-list');
		$group->post('', Playground\PlaygroundSaveAction::class)->setName('playground-save');
		$group->get('/{id}', Playground\PlaygroundFetchAction::class)->setName('playground-fetch');
		$group->put('/{id}', Playground\PlaygroundUpdateAction::class)->setName('playground-update');
		$group->delete('/{id}', Playground\PlaygroundDeleteAction::class)->setName('playground-delete');
	});
};
