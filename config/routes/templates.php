<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Template;

return function (App $app) {
    $app->group('/templates', function (RouteCollectorProxy $group) {
        // Template
        $group->get('', Template\TemplateListAction::class)->setName('template-list');
        $group->get('/{template}', Template\TemplateFetchAction::class)->setName('template-fetch');
        $group->post('/{template}', Template\TemplateSaveAction::class)->setName('template-save');
        $group->delete('/{template}', Template\TemplateDeleteAction::class)->setName('template-delete');
        $group->map(['HEAD'], '/{template}', Template\TemplateExistsAction::class)->setName('template-exists');
    });
};
