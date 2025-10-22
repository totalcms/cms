<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\Admin404Action;
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
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\CollectionMetaAccessMiddleware;
use TotalCMS\Middleware\Access\DocsAccessMiddleware;
use TotalCMS\Middleware\Access\MailerAccessMiddleware;
use TotalCMS\Middleware\Access\PlaygroundAccessMiddleware;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Access\TemplateAccessMiddleware;
use TotalCMS\Middleware\Access\UtilsAccessMiddleware;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;

return function (App $app): void {
	$app->redirect('/', '/admin', 301);

	$app->group('/admin', function (RouteCollectorProxy $group): void {
		// Display Admin Interface
		$group->get('', AdminIndexAction::class)->setName('admin-index');

		$group->get('/schemas[/{schema}[/{id}]]', AdminSchemaAction::class)->setName('admin-schema')->add(SchemaAccessMiddleware::class);
		$group->post('/schemas/new', AdminSchemaAction::class)->setName('admin-schema-duplicate')->add(SchemaAccessMiddleware::class);

		$group->get('/templates[/{path:.*}]', AdminTemplateAction::class)->setName('admin-template')->add(TemplateAccessMiddleware::class);
		$group->post('/templates/new', AdminTemplateAction::class)->setName('admin-template-duplicate')->add(TemplateAccessMiddleware::class);

		$group->get('/collections/new', AdminCollectionAction::class)->setName('admin-collection-new')->add(CollectionMetaAccessMiddleware::class);
		$group->get('/collections/{collection}/edit', AdminCollectionAction::class)->setName('admin-collection-edit')->add(CollectionMetaAccessMiddleware::class);

		$group->get('/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection')->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/{id}', AdminCollectionAction::class)->setName('admin-collection-post')->add(CollectionAccessMiddleware::class);

		$group->get('/docs[/{page:.*}]', AdminDocsAction::class)->setName('admin-docs')->add(DocsAccessMiddleware::class);

		$group->get('/profile', AdminEditProfileAction::class)->setName('admin-profile');

		$group->get('/utils/access-groups[/{action}]', AdminUtilsAction::class)->setName('admin-utils-access-groups')->add(AdminOnlyMiddleware::class);
		$group->get('/utils/api-keys[/{action}]', AdminUtilsAction::class)->setName('admin-utils-api-keys')->add(AdminOnlyMiddleware::class);

		$group->get('/utils[/{page}[/{action}]]', AdminUtilsAction::class)->setName('admin-utils')->add(UtilsAccessMiddleware::class);
		$group->post('/utils[/{page}[/{action}]]', AdminUtilsAction::class)->setName('admin-utils-post')->add(UtilsAccessMiddleware::class);

		$group->get('/playground[/{id}]', AdminPlaygroundAction::class)->setName('admin-playground')->add(PlaygroundAccessMiddleware::class);
		$group->post('/playground[/{id}]', AdminPlaygroundAction::class)->setName('admin-playground-post')->add(PlaygroundAccessMiddleware::class);

		$group->get('/mailer[/{id}]', AdminMailerAction::class)->setName('admin-mail')->add(MailerAccessMiddleware::class);
		$group->post('/mailer[/{id}]', AdminMailerAction::class)->setName('admin-mail-post')->add(MailerAccessMiddleware::class);

		$group->get('/settings[/{section}]', AdminSettingsAction::class)->setName('admin-settings')->add(AdminOnlyMiddleware::class);
		$group->post('/settings/{section}', AdminSettingsSaveSectionAction::class)->setName('admin-settings-save-section')->add(AdminOnlyMiddleware::class);

		$group->get('/imageworks', AdminImageworksAction::class)->setName('imageworks');
		$group->any('/filelinks', AdminFileLinksAction::class)->setName('filelinks');

		// Catch-all 404 route - MUST BE LAST
		$group->any('/{path:.*}', Admin404Action::class)->setName('admin-404');
	})->add(AuthMiddleware::class);
};
