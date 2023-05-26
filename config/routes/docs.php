<?php

use Slim\App;
use TotalCMS\Action\OpenApi\DocIndexAction;
use TotalCMS\Action\OpenApi\DocVersion1Action;

return function (App $app) {
    // Redirect to Swagger documentation
    $app->get('/docs', DocIndexAction::class);

    // Swagger API documentation
    $app->get('/docs/v1', DocVersion1Action::class)->setName('docs');
};
