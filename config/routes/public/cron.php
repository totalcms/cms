<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Cron\CronJobsAction;
use TotalCMS\Middleware\Cron\CronTokenMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

/**
 * HTTP equivalents of the `jobs:process` and `automations:process` cron lines,
 * for hosts whose scheduler can only fetch a URL.
 *
 * A `/cron/` prefix rather than hanging these off the existing groups: a
 * `/automations/process` route would sit beside `POST /automations/{id}` and be
 * shadowed the moment somebody names an automation "process".
 *
 * Public rather than under `/api` — a URL a customer pastes into cPanel should
 * not require knowing about the API prefix. NoCache because a cached cron URL
 * is a silently dead cron.
 */
return function (RouteCollectorProxyInterface $app): void {
	$app->group('/cron', function (RouteCollectorProxy $group): void {
		$group->get('/jobs', CronJobsAction::class)->setName('cron-jobs');
	})
		->add(CronTokenMiddleware::class)
		->add(NoCacheMiddleware::class);
};
