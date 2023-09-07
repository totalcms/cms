<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Schema;

return function (App $app) {
    $app->group('/schemas', function (RouteCollectorProxy $group) {
        // Collection Schema
        $group->get('/', Schema\SchemaListAction::class)->setName('schema-list');
        $group->get('/{id}', Schema\SchemaFetchAction::class)->setName('schema-fetch');

        // Pro Only License
        $group->post('/', Schema\SchemaSaveAction::class)->setName('schema-save');
        $group->delete('/{id}', Schema\SchemaDeleteAction::class)->setName('schema-delete');
    });
};
