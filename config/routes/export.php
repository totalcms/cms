<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Export;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;

return function (App $app): void {
	$app->group('/export', function (RouteCollectorProxy $group): void {
		$group->get('/collections/{collection}[/json]', Export\ExportJsonAction::class)->setName('export-json')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/csv', Export\ExportCsvAction::class)->setName('export-csv')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/zip', Export\ExportZipAction::class)->setName('export-zip')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/{id}/zip', Export\ExportObjectZipAction::class)->setName('export-object-zip')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/{id}/{property}/deck[/json]', Export\ExportDeckJsonAction::class)->setName('export-deck-json')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/{id}/{property}/deck/csv', Export\ExportDeckCsvAction::class)->setName('export-deck-csv')->add(CollectionAccessMiddleware::class);
		$group->get('/schemas/{schema}', Export\ExportSchemaAction::class)->setName('export-schema')->add(SchemaAccessMiddleware::class);
	})->add(AuthMiddleware::class);

	// JumpStart export routes — support API key auth for CLI push/pull
	$app->group('/export', function (RouteCollectorProxy $group): void {
		$group->get('/jumpstart', Export\ExportJumpStartAction::class)->setName('export-jumpstart');
		$group->get('/jumpstart/demo', Export\ExportJumpStartDemoAction::class)->setName('export-jumpstart-demo');
	})->add(DualAuthMiddleware::class);
};
