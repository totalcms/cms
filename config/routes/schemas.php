<?php

use App\Action\Schema;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/schemas', function (RouteCollectorProxy $group) {
        // Collection Schema
        $group->get('/{collection}', Schema\SchemaFetchAction::class)->setName('schema-fetch');

        // Pro Only License
        $group->post('/{collection}', Schema\SchemaSaveAction::class)->setName('schema-save');
    });
};
