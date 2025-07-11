<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\AdminCollectionAction;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Admin\AdminEditProfileAction;
use TotalCMS\Action\Admin\AdminFileLinksAction;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminSchemaAction;
use TotalCMS\Action\Admin\AdminSettingsAction;
use TotalCMS\Action\Admin\AdminSettingsSaveAction;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Action\Admin\AdminPlaygroundAction;
use TotalCMS\Middleware\AuthMiddleware;

return function (App $app) {
	$app->redirect('/', '/admin', 301);

	$app->group('/admin', function (RouteCollectorProxy $group) {
		// Display Admin Interface
		$group->get('', AdminIndexAction::class)->setName('admin-index');

		$group->get('/schemas[/{schema}[/{id}]]', AdminSchemaAction::class)->setName('admin-schema');
		$group->post('/schemas/new', AdminSchemaAction::class)->setName('admin-schema-duplicate');
		$group->get('/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection');
		$group->get('/docs[/{page}]', AdminDocsAction::class)->setName('admin-docs');
		$group->post('/collections/{collection}/{id}', AdminCollectionAction::class)->setName('admin-collection-post');

		$group->get('/profile', AdminEditProfileAction::class)->setName('admin-profile');

		$group->get('/utils[/{page}]', AdminUtilsAction::class)->setName('admin-utils');
		$group->post('/utils[/{page}]', AdminUtilsAction::class)->setName('admin-utils-post');

		$group->get('/playground', AdminPlaygroundAction::class)->setName('admin-playground');
		$group->post('/playground', AdminPlaygroundAction::class)->setName('admin-playground-post');

		$group->get('/settings', AdminSettingsAction::class)->setName('admin-settings');
		$group->post('/settings', AdminSettingsSaveAction::class)->setName('admin-settings-save');

		$group->get('/imageworks', AdminImageworksAction::class)->setName('imageworks');
		$group->any('/filelinks', AdminFileLinksAction::class)->setName('filelinks');
	})->add(AuthMiddleware::class);
};
