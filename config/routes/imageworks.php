<?php

use App\Action\ImageWorks;
use App\Middleware\RobotsTagMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/imageworks', function (RouteCollectorProxy $group) {
        $group->get('/{id}.{format}', ImageWorks\ImageWorksImageFetchAction::class)
        ->setName('image-fetch-short');

        $group->get('/{collection}/{id}/{property}.{format}', ImageWorks\ImageWorksImageFetchAction::class)
        ->setName('image-fetch');

        $group->get('/{id}/{filename}', ImageWorks\ImageWorksGalleryFetchAction::class)
        ->setName('gallery-image-fetch-short');

        $group->get('/{collection}/{id}/{property}/{filename}', ImageWorks\ImageWorksGalleryFetchAction::class)
        ->setName('gallery-image-fetch');

        // !$group->delete('/{collection}/{id}/{property}/{file}', ImageWorks\ImageWorksClearCacheAction::class)
        // !->setName('clear-cache');
    })->add(RobotsTagMiddleware::class);
};
