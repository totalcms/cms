<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Schema;

return function (App $app) {
	$app->group('/schemas', function (RouteCollectorProxy $group) {
		// Collection Schema
		$group->get('', Schema\SchemaListAction::class)->setName('schema-list');
		$group->get('/{id}', Schema\SchemaFetchAction::class)->setName('schema-fetch');
		$group->map(['HEAD'], '/{id}', Schema\SchemaExistsAction::class)->setName('schema-exists');

		// Pro Only License
		$group->post('', Schema\SchemaSaveAction::class)->setName('schema-save');
		$group->put('/{id}', Schema\SchemaUpdateAction::class)->setName('schema-update');
		$group->delete('/{id}', Schema\SchemaDeleteAction::class)->setName('schema-delete');
	});
};
