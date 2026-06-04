<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\Admin404Action;
use TotalCMS\Action\Admin\AdminAutomationsAction;
use TotalCMS\Action\Admin\AdminBuilderAction;
use TotalCMS\Action\Admin\AdminCollectionAction;
use TotalCMS\Action\Admin\AdminDataViewsAction;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Admin\AdminEditProfileAction;
use TotalCMS\Action\Admin\AdminExtensionsAction;
use TotalCMS\Action\Admin\AdminFileLinksAction;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Action\Admin\AdminMailerAction;
use TotalCMS\Action\Admin\AdminPlaygroundAction;
use TotalCMS\Action\Admin\AdminSchemaAction;
use TotalCMS\Action\Admin\AdminSettingsAction;
use TotalCMS\Action\Admin\AdminSettingsSaveSectionAction;
use TotalCMS\Action\Admin\AdminUtilsAction;
use TotalCMS\Action\Admin\Builder\BuilderPreviewAction;
use TotalCMS\Action\Admin\Builder\BuilderReorderAction;
use TotalCMS\Action\Admin\ExtensionToggleAction;
use TotalCMS\Action\Admin\LogDownloadAction;
use TotalCMS\Action\Admin\SyncAction;
use TotalCMS\Action\Admin\UpdateAction;
use TotalCMS\Action\Automation\AutomationReenableAction;
use TotalCMS\Action\Automation\AutomationRunNowAction;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\CollectionMetaAccessMiddleware;
use TotalCMS\Middleware\Access\DataViewsAccessMiddleware;
use TotalCMS\Middleware\Access\DocsAccessMiddleware;
use TotalCMS\Middleware\Access\MailerAccessMiddleware;
use TotalCMS\Middleware\Access\PlaygroundAccessMiddleware;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Access\TemplateAccessMiddleware;
use TotalCMS\Middleware\Access\UtilsAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Cache\VersionCheckMiddleware;
use TotalCMS\Middleware\License\AccessGroupsEditionMiddleware;
use TotalCMS\Middleware\License\ApiKeysEditionMiddleware;
use TotalCMS\Middleware\License\AutomationsEditionMiddleware;
use TotalCMS\Middleware\License\CollectionEditionMiddleware;
use TotalCMS\Middleware\License\DataViewsEditionMiddleware;
use TotalCMS\Middleware\License\MailerEditionMiddleware;
use TotalCMS\Middleware\License\SchemaEditionMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;
use TotalCMS\Middleware\Security\CSRFProtectionMiddleware;
use TotalCMS\Middleware\UserLocaleMiddleware;

return function (App $app): void {
	$app->group('/admin', function (RouteCollectorProxy $group): void {
		// Display Admin Interface
		$group->get('', AdminIndexAction::class)->setName('admin-index');

		$group->get('/schemas[/{schema}[/{id}]]', AdminSchemaAction::class)->setName('admin-schema')->add(SchemaEditionMiddleware::class)->add(SchemaAccessMiddleware::class);
		$group->post('/schemas/new', AdminSchemaAction::class)->setName('admin-schema-duplicate')->add(SchemaEditionMiddleware::class)->add(SchemaAccessMiddleware::class);

		// Builder (replaces Templates) — available to all editions
		$group->post('/builder/preview', BuilderPreviewAction::class)->setName('admin-builder-preview')->add(TemplateAccessMiddleware::class);
		$group->post('/builder/reorder', BuilderReorderAction::class)->setName('admin-builder-reorder')->add(TemplateAccessMiddleware::class);
		$group->get('/builder[/{section}[/{path:.*}]]', AdminBuilderAction::class)->setName('admin-builder')->add(TemplateAccessMiddleware::class);

		$group->get('/collections/new', AdminCollectionAction::class)->setName('admin-collection-new')->add(CollectionMetaAccessMiddleware::class);
		$group->get('/collections/{collection}/edit', AdminCollectionAction::class)->setName('admin-collection-edit')->add(CollectionEditionMiddleware::class)->add(CollectionMetaAccessMiddleware::class);

		$group->get('/collections[/{collection}[/{id}]]', AdminCollectionAction::class)->setName('admin-collection')->add(CollectionEditionMiddleware::class)->add(CollectionAccessMiddleware::class);
		$group->post('/collections/{collection}/{id}', AdminCollectionAction::class)->setName('admin-collection-post')->add(CollectionEditionMiddleware::class)->add(CollectionAccessMiddleware::class);

		$group->get('/docs[/{page:.*}]', AdminDocsAction::class)->setName('admin-docs')->add(DocsAccessMiddleware::class);

		$group->get('/profile', AdminEditProfileAction::class)->setName('admin-profile');

		$group->get('/utils/access-groups[/{action}]', AdminUtilsAction::class)->setName('admin-utils-access-groups')->add(AccessGroupsEditionMiddleware::class)->add(AdminOnlyMiddleware::class);
		$group->get('/utils/api-keys[/{action}]', AdminUtilsAction::class)->setName('admin-utils-api-keys')->add(ApiKeysEditionMiddleware::class)->add(AdminOnlyMiddleware::class);

		$group->get('/utils/logs/download', LogDownloadAction::class)->setName('admin-utils-logs-download')->add(UtilsAccessMiddleware::class);

		$group->post('/utils/update/apply', UpdateAction::class)->setName('admin-update-apply')->add(AdminOnlyMiddleware::class);

		$group->get('/utils[/{page}[/{action}]]', AdminUtilsAction::class)->setName('admin-utils')->add(UtilsAccessMiddleware::class);
		$group->post('/utils[/{page}[/{action}]]', AdminUtilsAction::class)->setName('admin-utils-post')->add(UtilsAccessMiddleware::class);

		$group->post('/sync/{action}', SyncAction::class)->setName('admin-sync')->add(AdminOnlyMiddleware::class);

		$group->get('/dataviews[/{id}]', AdminDataViewsAction::class)->setName('admin-dataviews')->add(DataViewsEditionMiddleware::class)->add(DataViewsAccessMiddleware::class);

		$group->get('/playground[/{id}]', AdminPlaygroundAction::class)->setName('admin-playground')->add(PlaygroundAccessMiddleware::class);
		$group->post('/playground[/{id}]', AdminPlaygroundAction::class)->setName('admin-playground-post')->add(PlaygroundAccessMiddleware::class);

		$group->get('/mailer[/{id}]', AdminMailerAction::class)->setName('admin-mail')->add(MailerEditionMiddleware::class)->add(MailerAccessMiddleware::class);
		$group->post('/mailer[/{id}]', AdminMailerAction::class)->setName('admin-mail-post')->add(MailerEditionMiddleware::class)->add(MailerAccessMiddleware::class);

		$group->get('/automations[/{id}]', AdminAutomationsAction::class)->setName('admin-automations')->add(AutomationsEditionMiddleware::class)->add(AdminOnlyMiddleware::class);
		$group->post('/automations[/{id}]', AdminAutomationsAction::class)->setName('admin-automations-post')->add(AutomationsEditionMiddleware::class)->add(AdminOnlyMiddleware::class);
		$group->post('/automations/{id}/run', AutomationRunNowAction::class)->setName('admin-automations-run')->add(AutomationsEditionMiddleware::class)->add(AdminOnlyMiddleware::class);
		$group->post('/automations/{id}/enable', AutomationReenableAction::class)->setName('admin-automations-enable')->add(AutomationsEditionMiddleware::class)->add(AdminOnlyMiddleware::class);

		$group->get('/settings[/{section}]', AdminSettingsAction::class)->setName('admin-settings')->add(AdminOnlyMiddleware::class);
		$group->post('/settings/{section}', AdminSettingsSaveSectionAction::class)->setName('admin-settings-save-section')->add(AdminOnlyMiddleware::class);

		$group->get('/imageworks', AdminImageworksAction::class)->setName('imageworks');
		$group->any('/filelinks', AdminFileLinksAction::class)->setName('filelinks');

		// Extension management
		$group->get('/extensions', AdminExtensionsAction::class)->setName('admin-extensions')->add(AdminOnlyMiddleware::class);
		$group->post('/extensions/{extension:.+}/{action:enable|disable}', ExtensionToggleAction::class)->setName('admin-extension-toggle')->add(AdminOnlyMiddleware::class);
		$group->get('/extensions/{extension:.+}/review', TotalCMS\Action\Admin\AdminExtensionReviewAction::class)->setName('admin-extension-review')->add(AdminOnlyMiddleware::class);
		$group->get('/extensions/{extension:.+}/settings', TotalCMS\Action\Admin\ExtensionSettingsAction::class)->setName('admin-extension-settings')->add(AdminOnlyMiddleware::class);
		$group->post('/extensions/{extension:.+}/settings', TotalCMS\Action\Admin\ExtensionSettingsSaveAction::class)->setName('admin-extension-settings-save')->add(AdminOnlyMiddleware::class);

		// Extension admin pages (routed by extension system)
		$group->any('/ext/{vendor}/{name}/{path:.+}', TotalCMS\Action\Extension\ExtensionAdminRouteAction::class)->setName('admin-ext-route');

		// Catch-all 404 route - MUST BE LAST (excludes /admin/ext/ which is handled by extensions)
		$group->any('/{path:(?!ext/).*}', Admin404Action::class)->setName('admin-404');
	})
		// Innermost: runs after AuthMiddleware has established the session user,
		// right before the action renders, so the user's preferred locale drives
		// both the t() strings and the injected JS catalog.
		->add(UserLocaleMiddleware::class)
		->add(VersionCheckMiddleware::class)
		// CSRF after auth: only authed sessions need protection. The middleware
		// bypasses API-keyed requests automatically (non-cookie auth = no CSRF
		// surface). Forms get the token from TotalForm; HTMX gets it from the
		// meta tag in admin-dashboard.twig.
		->add(CSRFProtectionMiddleware::class)
		->add(AuthMiddleware::class)
		->add(NoCacheMiddleware::class);
};
