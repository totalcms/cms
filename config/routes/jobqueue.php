<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\JobQueue;
use TotalCMS\Middleware\AuthMiddleware;

return function (App $app): void {
	$app->group('/jobqueue', function (RouteCollectorProxy $group): void {
		$group->delete('', JobQueue\JobQueueClearAction::class)->setName('clear-queue');
		$group->delete('/{collection}', JobQueue\JobQueueClearCollectionAction::class)->setName('clear-queue-collection');

		$group->get('/stats', JobQueue\JobQueueStatsAction::class)->setName('queue-stats');
		$group->get('/stats/{collection}', JobQueue\JobQueueStatsCollectionAction::class)->setName('queue-stats-collection');
	})->add(AuthMiddleware::class);
};
