<?php

use Slim\App;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminSchemaAction;

return function (App $app) {
	// Display Admin Interface
	$app->get('/admin', AdminIndexAction::class)->setName('admin-index');

	$app->get('/admin/schemas[/{schema}]', AdminSchemaAction::class)->setName('admin-schema');

	$app->get('/admin/collections', AdminIndexAction::class)->setName('admin-index');
	$app->get('/admin/collections/new', AdminIndexAction::class)->setName('admin-index');
	$app->get('/admin/collections/{collection}/edit', AdminIndexAction::class)->setName('admin-index');
	$app->get('/admin/collections/{collection}', AdminIndexAction::class)->setName('admin-index');
	$app->get('/admin/collections/{collection}/add', AdminIndexAction::class)->setName('admin-index');
	$app->get('/admin/collections/{collection}/{id}', AdminIndexAction::class)->setName('admin-index');

	$app->get('/admin/docs', AdminImageworksAction::class)->setName('imageworks');
	$app->get('/admin/logs', AdminImageworksAction::class)->setName('imageworks');
	$app->get('/admin/settings', AdminImageworksAction::class)->setName('imageworks');

	$app->get('/admin/imageworks', AdminImageworksAction::class)->setName('imageworks');
};
