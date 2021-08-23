<?php

use App\Action\Froala;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/froala', function (RouteCollectorProxy $group) {
        // {id} - object ID
        $group->post('/depot/{id}', Froala\FroalaUploadFileAction::class)->setName('froala-upload');
        $group->get('/gallery/{id}', Froala\FroalaUploadFileAction::class)->setName('froala-image-fetch');
        $group->post('/gallery/{id}', Froala\FroalaUploadFileAction::class)->setName('froala-upload');
        $group->delete('/gallery/{id}', Froala\FroalaDeleteImageAction::class)->setName('froala-image-delete');
    });
};