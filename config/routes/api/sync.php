<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Sync\SyncImportAction;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// Server-to-server sync receive endpoint. Mirrors `/api/import/jumpstart`
	// auth-wise (DualAuthMiddleware accepts session + X-API-Key), but always
	// runs the importer in upsert mode so a sync push from a local environment
	// produces a true mirror on the remote rather than silently skipping
	// existing rows. See SyncImportAction for the rationale.
	$app->group('/sync', function (RouteCollectorProxy $group): void {
		$group->post('/import', SyncImportAction::class)->setName('sync-import');
	})->add(DualAuthMiddleware::class);
};
