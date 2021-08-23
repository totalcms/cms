<?php

use App\Action\Import;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/import', function (RouteCollectorProxy $group) {
        $group->post('/{collection}[/factory]', Import\ImportFactoryAction::class)->setName('import-factory');
        $group->post('/{collection}/yaml', Import\ImportYAMLAction::class)->setName('import-yaml');
        $group->post('/{collection}/json', Import\ImportJSONAction::class)->setName('import-json');
        $group->post('/{collection}/csv', Import\ImportCSVAction::class)->setName('import-csv');
        $group->post('/{collection}/rss', Import\ImportRSSAction::class)->setName('import-rss');
        $group->post('/{collection}/url', Import\ImportURLAction::class)->setName('import-url');
        $group->post('/{collection}/wordpress', Import\ImportWordpressAction::class)->setName('import-wordpress');
    });
};
