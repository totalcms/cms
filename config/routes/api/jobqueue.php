<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\JobQueue;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/jobqueue', function (RouteCollectorProxy $group): void {
		$group->delete('', JobQueue\JobQueueClearAction::class)->setName('clear-queue');
		$group->delete('/{collection}', JobQueue\JobQueueClearCollectionAction::class)->setName('clear-queue-collection');

		$group->get('/jobs/html', JobQueue\JobQueueJobsHtmlAction::class)->setName('queue-jobs-html');
		$group->get('/stats', JobQueue\JobQueueStatsAction::class)->setName('queue-stats');
		$group->get('/stats/html', JobQueue\JobQueueStatsHtmlAction::class)->setName('queue-stats-html');
		$group->get('/stats/{collection}', JobQueue\JobQueueStatsCollectionAction::class)->setName('queue-stats-collection');
		$group->get('/stats/{collection}/html', JobQueue\JobQueueStatsHtmlAction::class)->setName('queue-stats-collection-html');
	})->add(AuthMiddleware::class);
};
