<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Orphan\OrphanCleanupAction;
use TotalCMS\Action\Orphan\OrphanScanAction;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/orphan', function (RouteCollectorProxy $group): void {
		$group->get('/scan', OrphanScanAction::class)->setName('orphan-scan');
		$group->post('/cleanup', OrphanCleanupAction::class)->setName('orphan-cleanup');
	})->add(AuthMiddleware::class);
};
