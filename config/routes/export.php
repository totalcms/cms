<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Export;

return function (App $app) {
	$app->group('/export', function (RouteCollectorProxy $group) {
		$group->get('/collections/{collection}[/json]', Export\ExportJsonAction::class)->setName('export-json');
		$group->get('/collections/{collection}/csv', Export\ExportCsvAction::class)->setName('export-csv');
		$group->get('/collections/{collection}/zip', Export\ExportZipAction::class)->setName('export-zip');
		$group->get('/schemas/{schema}', Export\ExportSchemaAction::class)->setName('export-schema');
	});
};
