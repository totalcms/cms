<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\AccessGroup\AccessGroupDeleteAction;
use TotalCMS\Action\Admin\AccessGroup\AccessGroupSaveAction;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (App $app): void {
	// API endpoints for managing access groups (requires super admin)
	$app->group('/access-groups', function (RouteCollectorProxy $group): void {
		$group->post('', AccessGroupSaveAction::class)->setName('access-group-save');
		$group->put('/{id}', AccessGroupSaveAction::class)->setName('access-group-edit');
		$group->delete('/{id}', AccessGroupDeleteAction::class)->setName('access-group-delete');
	})->add(AdminOnlyMiddleware::class)
		->add(AuthMiddleware::class);
};
