<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Designer;
use TotalCMS\Middleware\Access\DesignerAccessMiddleware;
use TotalCMS\Middleware\License\TemplatesEditionMiddleware;
use TotalCMS\Middleware\Security\ExternalCorsMiddleware;

return function (App $app): void {
	// Designer API - public routes with token-based auth (requires Standard+ edition)
	$app->group('/designer/templates', function (RouteCollectorProxy $group): void {
		$group->put('/{path:.*}', Designer\DesignerTemplateUpdateAction::class)->setName('designer-template-update');
	})->add(DesignerAccessMiddleware::class)
		->add(TemplatesEditionMiddleware::class)
		->add(ExternalCorsMiddleware::class);
};
