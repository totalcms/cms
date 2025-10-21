<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Export;
use TotalCMS\Middleware\AuthMiddleware;
use TotalCMS\Middleware\CollectionAccessMiddleware;

return function (App $app): void {
	$app->group('/export', function (RouteCollectorProxy $group): void {
		$group->get('/collections/{collection}[/json]', Export\ExportJsonAction::class)->setName('export-json')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/csv', Export\ExportCsvAction::class)->setName('export-csv')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/zip', Export\ExportZipAction::class)->setName('export-zip')->add(CollectionAccessMiddleware::class);
		$group->get('/schemas/{schema}', Export\ExportSchemaAction::class)->setName('export-schema');
		$group->get('/jumpstart', Export\ExportJumpStartAction::class)->setName('export-jumpstart');
		$group->get('/jumpstart/demo', Export\ExportJumpStartDemoAction::class)->setName('export-jumpstart-demo');
	})->add(AuthMiddleware::class);
};
