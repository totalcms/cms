<?php

use App\Action\Collection;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/schemas', function (RouteCollectorProxy $group) {
        // Collection Schema
        $group->get('/{collection}', Collection\Schema\SchemaFetchAction::class)->setName('schema-fetch');

        // Pro Only License
        $group->post('/{collection}', Collection\Schema\SchemaSaveAction::class)->setName('schema-save');
    });
};