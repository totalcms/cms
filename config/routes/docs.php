<?php

use Slim\App;

return function (App $app) {
    // Redirect to Swagger documentation
    $app->get('/docs', \App\Action\OpenApi\DocIndexAction::class);

    // Swagger API documentation
    $app->get('/docs/v1', \App\Action\OpenApi\DocVersion1Action::class)->setName('docs');
};
