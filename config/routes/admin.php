<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\AdminCollectionAction;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Admin\AdminEditProfileAction;
use TotalCMS\Action\Admin\AdminFileLinksAction;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminMailerAction;
use TotalCMS\Action\Admin\AdminPlaygroundAction;
use TotalCMS\Action\Admin\AdminSchemaAction;
use TotalCMS\Action\Admin\AdminSettingsAction;
use TotalCMS\Action\Admin\AdminSettingsSaveSectionAction;
use TotalCMS\Action\Admin\AdminTemplateAction;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Middleware\AuthMiddleware;
use TotalCMS\Middleware\CollectionAccessMiddleware;
use TotalCMS\Middleware\SchemaAccessMiddleware;

return function (App $app): void {
	$app->redirect('/', '/admin', 301);

	$app->group('/admin', function (RouteCollectorProxy $group): void {
		// Display Admin Interface
		$group->get('', AdminIndexAction::class)->setName('admin-index');

		$group->get('/schemas[/{schema}[/{id}]]', AdminSchemaAction::class)->setName('admin-schema')->add(SchemaAccessMiddleware::class);
		$group->post('/schemas/new', AdminSchemaAction::class)->setName('admin-schema-duplicate')->add(SchemaAccessMiddleware::class);

		$group->get('/templates[/{path:.*}]', AdminTemplateAction::class)->setName('admin-template');
		$group->post('/templates/new', AdminTemplateAction::class)->setName('admin-template-duplicate');

		$group->get('/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/{id}', AdminCollectionAction::class)->setName('admin-collection-post')->add(CollectionAccessMiddleware::class);

		$group->get('/docs[/{page:.*}]', AdminDocsAction::class)->setName('admin-docs');

		$group->get('/profile', AdminEditProfileAction::class)->setName('admin-profile');

		$group->get('/utils[/{page}[/{action}]]', AdminUtilsAction::class)->setName('admin-utils');
		$group->post('/utils[/{page}[/{action}]]', AdminUtilsAction::class)->setName('admin-utils-post');

		$group->get('/playground[/{id}]', AdminPlaygroundAction::class)->setName('admin-playground');
		$group->post('/playground[/{id}]', AdminPlaygroundAction::class)->setName('admin-playground-post');

		$group->get('/mailer[/{id}]', AdminMailerAction::class)->setName('admin-mail');
		$group->post('/mailer[/{id}]', AdminMailerAction::class)->setName('admin-mail-post');

		$group->get('/settings[/{section}]', AdminSettingsAction::class)->setName('admin-settings');
		$group->post('/settings/{section}', AdminSettingsSaveSectionAction::class)->setName('admin-settings-save-section');

		$group->get('/imageworks', AdminImageworksAction::class)->setName('imageworks');
		$group->any('/filelinks', AdminFileLinksAction::class)->setName('filelinks');
	})->add(AuthMiddleware::class);
};
