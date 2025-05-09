<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Import;

return function (App $app) {
	$app->group('/import', function (RouteCollectorProxy $group) {
		$group->post('/collections/{collection}/factory', Import\ImportFactoryAction::class)->setName('import-factory');
		$group->post('/collections/{collection}/csv', Import\ImportCsvAction::class)->setName('import-csv');
		$group->post('/collections/{collection}/json', Import\ImportJsonAction::class)->setName('import-json');
		//! $group->post('/{collection}/wordpress', Import\ImportWordpressAction::class)->setName('import-wordpress');
		$group->post('/schemas/{schema}[/json]', Import\ImportSchemaAction::class)->setName('import-schema');
	});
};
