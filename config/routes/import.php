<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Import;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\License\RssImportEditionMiddleware;

return function (App $app): void {
	$app->group('/import', function (RouteCollectorProxy $group): void {
		$group->post('/collections/{collection}/factory', Import\ImportFactoryAction::class)->setName('import-factory')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/csv', Import\ImportCsvAction::class)->setName('import-csv')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/deck/csv', Import\ImportDeckCsvAction::class)->setName('import-deck-csv')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/deck/json', Import\ImportDeckJsonAction::class)->setName('import-deck-json')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/json', Import\ImportJsonAction::class)->setName('import-json')->add(CollectionAccessMiddleware::class);
		$group->post('/wordpress-analyze', Import\ImportWordpressAnalyzeAction::class)->setName('import-wordpress-analyze')->add(RssImportEditionMiddleware::class);
		$group->post('/wordpress', Import\ImportWordpressAction::class)->setName('import-wordpress')->add(RssImportEditionMiddleware::class);

		$group->post('/schemas', Import\ImportSchemaAction::class)->setName('import-schema')->add(SchemaAccessMiddleware::class);
		$group->post('/totalcms-one', Import\ImportTotalCmsOneAction::class)->setName('import-totalcms-one');
		$group->post('/jumpstart', Import\ImportJumpStartAction::class)->setName('import-jumpstart');
		$group->post('/alloy-analyze', Import\ImportAlloyAnalyzeAction::class)->setName('import-alloy-analyze');
		$group->post('/alloy', Import\ImportAlloyAction::class)->setName('import-alloy');
		$group->post('/rss-analyze', Import\ImportRssAnalyzeAction::class)->setName('import-rss-analyze')->add(RssImportEditionMiddleware::class);
		$group->post('/rss', Import\ImportRssAction::class)->setName('import-rss')->add(RssImportEditionMiddleware::class);
	})->add(AuthMiddleware::class);
};
