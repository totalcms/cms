<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\AdminCollectionAction;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminLogsAction;
use TotalCMS\Action\Admin\AdminSchemaAction;
use TotalCMS\Action\Admin\AdminSettingsAction;

return function (App $app) {
	$app->group('/admin', function (RouteCollectorProxy $group) {
		// Display Admin Interface
		$group->get('', AdminIndexAction::class)->setName('admin-index');

		$group->get('/schemas[/{schema}]', AdminSchemaAction::class)->setName('admin-schema');
		$group->get('/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection');
		$group->get('/docs[/{page}]', AdminDocsAction::class)->setName('admin-docs');

		$group->get('/logs', AdminLogsAction::class)->setName('admin-logs');
		$group->get('/settings', AdminSettingsAction::class)->setName('admin-settings');

		$group->get('/imageworks', AdminImageworksAction::class)->setName('imageworks');
	});
};
