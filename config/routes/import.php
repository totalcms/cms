<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Import;

return function (App $app) {
	$app->group('/import', function (RouteCollectorProxy $group) {
		$group->post('/{collection}/factory', Import\ImportFactoryAction::class)->setName('import-factory');
		$group->post('/{collection}/csv', Import\ImportCsvAction::class)->setName('import-csv');
		// !$group->post('/{collection}/json', Import\ImportJsonAction::class)->setName('import-json');
		// !$group->post('/{collection}/rss', Import\ImportRssAction::class)->setName('import-rss');
		// !$group->post('/{collection}/url', Import\ImportUrlAction::class)->setName('import-url');
		// !$group->post('/{collection}/wordpress', Import\ImportWordpressAction::class)->setName('import-wordpress');
	});
};
