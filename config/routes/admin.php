<?php

use Slim\App;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminSchemaAction;
use TotalCMS\Action\Admin\AdminCollectionAction;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Admin\AdminLogsAction;
use TotalCMS\Action\Admin\AdminSettingsAction;

return function (App $app) {
	// Display Admin Interface
	$app->get('/admin', AdminIndexAction::class)->setName('admin-index');

	$app->get('/admin/schemas[/{schema}]', AdminSchemaAction::class)->setName('admin-schema');
	$app->get('/admin/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection');

	$app->get('/admin/docs', AdminDocsAction::class)->setName('admin-docs');
	$app->get('/admin/logs', AdminLogsAction::class)->setName('admin-logs');
	$app->get('/admin/settings', AdminSettingsAction::class)->setName('admin-settings');

	$app->get('/admin/imageworks', AdminImageworksAction::class)->setName('imageworks');
};
