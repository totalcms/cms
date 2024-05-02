<?php

use Slim\App;
use TotalCMS\Action\Docs\DocPageAction;
use TotalCMS\Action\OpenApi\DocRedirectAction;
use TotalCMS\Action\OpenApi\DocVersion3Action;

return function (App $app) {
    // Swagger API documentation
    $app->get('/docs/api', DocRedirectAction::class); // redirect
    $app->get('/docs/api/v3', DocVersion3Action::class)->setName('api-docs');

    // Documentation
    $app->get('/docs/[{page}]', DocPageAction::class)->setName('docs-page');
};
