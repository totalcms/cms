<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Import;
use TotalCMS\Middleware\AuthMiddleware;
use TotalCMS\Middleware\CollectionAccessMiddleware;

return function (App $app): void {
	$app->group('/import', function (RouteCollectorProxy $group): void {
		$group->post('/collections/{collection}/factory', Import\ImportFactoryAction::class)->setName('import-factory')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/csv', Import\ImportCsvAction::class)->setName('import-csv')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/json', Import\ImportJsonAction::class)->setName('import-json')->add(CollectionAccessMiddleware::class);
		// ! $group->post('/{collection}/wordpress', Import\ImportWordpressAction::class)->setName('import-wordpress');

		$group->post('/schemas', Import\ImportSchemaAction::class)->setName('import-schema');
		$group->post('/totalcms-one', Import\ImportTotalCmsOneAction::class)->setName('import-totalcms-one');
		$group->post('/jumpstart', Import\ImportJumpStartAction::class)->setName('import-jumpstart');
		$group->post('/alloy-analyze', Import\ImportAlloyAnalyzeAction::class)->setName('import-alloy-analyze');
		$group->post('/alloy', Import\ImportAlloyAction::class)->setName('import-alloy');
	})->add(AuthMiddleware::class);
};
