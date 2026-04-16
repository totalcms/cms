<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Schema;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Middleware\License\SchemaEditionMiddleware;
use TotalCMS\Middleware\Security\ExternalCorsMiddleware;

return function (App $app): void {
	// Read-only schema routes (allow API keys)
	$app->group('/schemas', function (RouteCollectorProxy $group): void {
		$group->get('', Schema\SchemaListAction::class)->setName('schema-list');
		$group->get('/{id}', Schema\SchemaFetchAction::class)->setName('schema-fetch');
		$group->map(['HEAD'], '/{id}', Schema\SchemaExistsAction::class)->setName('schema-exists');
	})->add(SchemaEditionMiddleware::class)
		->add(SchemaAccessMiddleware::class)
		->add(DualAuthMiddleware::class)
		->add(ExternalCorsMiddleware::class);

	// Mutation schema routes (session-only, no API keys, Pro edition for custom schemas)
	$app->group('/schemas', function (RouteCollectorProxy $group): void {
		$group->post('', Schema\SchemaSaveAction::class)->setName('schema-save');
		$group->put('/{id}', Schema\SchemaUpdateAction::class)->setName('schema-update');
		$group->delete('/{id}', Schema\SchemaDeleteAction::class)->setName('schema-delete');
	})->add(SchemaEditionMiddleware::class)
		->add(SchemaAccessMiddleware::class)
		->add(AuthMiddleware::class);
};
