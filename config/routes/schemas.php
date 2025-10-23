<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Schema;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;

return function (App $app): void {
	// Read-only schema routes (allow API keys)
	$app->group('/schemas', function (RouteCollectorProxy $group): void {
		$group->get('', Schema\SchemaListAction::class)->setName('schema-list');
		$group->get('/{id}', Schema\SchemaFetchAction::class)->setName('schema-fetch');
		$group->map(['HEAD'], '/{id}', Schema\SchemaExistsAction::class)->setName('schema-exists');
	})->add(SchemaAccessMiddleware::class)
		->add(DualAuthMiddleware::class);

	// Mutation schema routes (session-only, no API keys)
	$app->group('/schemas', function (RouteCollectorProxy $group): void {
		$group->post('', Schema\SchemaSaveAction::class)->setName('schema-save');
		$group->put('/{id}', Schema\SchemaUpdateAction::class)->setName('schema-update');
		$group->delete('/{id}', Schema\SchemaDeleteAction::class)->setName('schema-delete');
	})->add(SchemaAccessMiddleware::class)
		->add(AuthMiddleware::class);
};
