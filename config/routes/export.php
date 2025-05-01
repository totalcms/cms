<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Export;

return function (App $app) {
	$app->group('/export', function (RouteCollectorProxy $group) {
		$group->get('/{collection}[/json]', Export\ExportJsonAction::class)->setName('export-json');
		$group->get('/{collection}/csv', Export\ExportCsvAction::class)->setName('export-csv');
	});
};
