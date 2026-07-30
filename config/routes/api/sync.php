<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Sync\SyncExportAction;
use TotalCMS\Action\Sync\SyncImportAction;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// Server-to-server sync endpoints. Both live under /sync so a single
	// API-key path grant ("Sync Manager" in the endpoint picker) covers
	// push and pull. Auth mirrors `/api/import/jumpstart` / `/api/export/
	// jumpstart` (DualAuthMiddleware accepts session + X-API-Key).
	//
	//   POST /sync/import — receive a push. Always runs the importer in
	//     upsert mode so a sync push produces a true mirror on the remote
	//     rather than silently skipping existing rows. See SyncImportAction.
	//   GET /sync/export — serve a pull. The sync payload (custom schemas,
	//     templates, allowlisted collection objects); AdminOnly to match
	//     the export-jumpstart route it supersedes for sync use.
	$app->group('/sync', function (RouteCollectorProxy $group): void {
		$group->post('/import', SyncImportAction::class)->setName('sync-import');
		$group->get('/export', SyncExportAction::class)->setName('sync-export')->add(AdminOnlyMiddleware::class);
	})->add(DualAuthMiddleware::class);
};
