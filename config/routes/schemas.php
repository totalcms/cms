<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Schema;

return function (App $app) {
    $app->group('/schemas', function (RouteCollectorProxy $group) {
        // Collection Schema
        $group->get('/{type}', Schema\SchemaFetchAction::class)->setName('schema-fetch');

        // Pro Only License
        $group->post('/', Schema\SchemaSaveAction::class)->setName('schema-save');
    });
};
