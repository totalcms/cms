<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\AdminCollectionAction;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Action\Admin\AdminSchemaAction;
use TotalCMS\Action\Admin\AdminSettingsAction;
use Odan\Session\Middleware\SessionStartMiddleware;

return function (App $app) {
	$app->group('/admin', function (RouteCollectorProxy $group) {
		// Display Admin Interface
		$group->get('', AdminIndexAction::class)->setName('admin-index');

		$group->get('/schemas[/{schema}]', AdminSchemaAction::class)->setName('admin-schema');
		$group->get('/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection');
		$group->get('/docs[/{page}]', AdminDocsAction::class)->setName('admin-docs');

		$group->get('/utils[/{page}]', AdminUtilsAction::class)->setName('admin-utils');
		$group->get('/settings', AdminSettingsAction::class)->setName('admin-settings');

		$group->get('/imageworks', AdminImageworksAction::class)->setName('imageworks');
	})->add(SessionStartMiddleware::class);;
};
