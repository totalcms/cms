<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Template;

return function (App $app): void {
	$app->group('/templates', function (RouteCollectorProxy $group): void {
		// Template list (root level)
		$group->get('', Template\TemplateListAction::class)->setName('template-list');

		// List templates in a folder (must come before the fetch route to avoid conflicts)
		$group->get('/_list/{folder:.*}', Template\TemplateListAction::class)->setName('template-list-folder');

		// Templates with path (supports both root and nested folders)
		// The {path:.*} regex allows for slashes, capturing folder/template or just template
		$group->get('/{path:.*}', Template\TemplateFetchAction::class)->setName('template-fetch');
		$group->post('/{path:.*}', Template\TemplateSaveAction::class)->setName('template-save');
		$group->delete('/{path:.*}', Template\TemplateDeleteAction::class)->setName('template-delete');
		$group->map(['HEAD'], '/{path:.*}', Template\TemplateExistsAction::class)->setName('template-exists');
	});
};
