<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Schema;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Middleware\Access\SchemaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Middleware\License\EditionFeatureMiddleware;
use TotalCMS\Middleware\License\SchemaEditionMiddleware;

return function (App $app): void {
	$container = $app->getContainer();
	if ($container === null) {
		throw new \RuntimeException('Container not available');
	}

	// Read-only schema routes (allow API keys)
	$app->group('/schemas', function (RouteCollectorProxy $group): void {
		$group->get('', Schema\SchemaListAction::class)->setName('schema-list');
		$group->get('/{id}', Schema\SchemaFetchAction::class)->setName('schema-fetch');
		$group->map(['HEAD'], '/{id}', Schema\SchemaExistsAction::class)->setName('schema-exists');
	})->add(SchemaEditionMiddleware::class)
		->add(SchemaAccessMiddleware::class)
		->add(DualAuthMiddleware::class);

	// Mutation schema routes (session-only, no API keys, Pro edition for custom schemas)
	$app->group('/schemas', function (RouteCollectorProxy $group): void {
		$group->post('', Schema\SchemaSaveAction::class)->setName('schema-save');
		$group->put('/{id}', Schema\SchemaUpdateAction::class)->setName('schema-update');
		$group->delete('/{id}', Schema\SchemaDeleteAction::class)->setName('schema-delete');
	})->add(SchemaEditionMiddleware::class)
		->add(new EditionFeatureMiddleware(
			$container->get(EditionFeatureService::class),
			EditionFeature::CUSTOM_SCHEMAS
		))
		->add(SchemaAccessMiddleware::class)
		->add(AuthMiddleware::class);
};
