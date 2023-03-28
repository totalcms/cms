<?php

use App\Action\Template;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/templates', function (RouteCollectorProxy $group) {
        // Template
        $group->get('/{template}', Template\TemplateFetchAction::class)->setName('template-fetch');
        $group->post('/{template}', Template\TemplateSaveAction::class)->setName('template-save');
    });
};
