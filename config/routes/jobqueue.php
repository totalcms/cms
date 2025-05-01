<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\JobQueue;

return function (App $app) {
	$app->group('/jobqueue', function (RouteCollectorProxy $group) {
		$group->delete('', JobQueue\JobQueueClearAction::class)->setName('clear-queue');
		$group->delete('/{collection}', JobQueue\JobQueueClearCollectionAction::class)->setName('clear-queue-collection');
	});
};
