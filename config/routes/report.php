<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Report;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (App $app): void {
	$app->group('/report', function (RouteCollectorProxy $group): void {
		$group->get('/collections/{collection}/fields', Report\ReportFieldsAction::class)->setName('report-fields')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/csv', Report\ReportCsvAction::class)->setName('report-csv')->add(CollectionAccessMiddleware::class);
		$group->get('/collections/{collection}/json', Report\ReportJsonAction::class)->setName('report-json')->add(CollectionAccessMiddleware::class);
	})->add(AuthMiddleware::class);
};
