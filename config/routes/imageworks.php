<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\ImageWorks;
use TotalCMS\Middleware\RobotsTagMiddleware;

return function (App $app) {
    $app->group('/imageworks', function (RouteCollectorProxy $group) {
        $group->get('/{id}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch-short');
        $group->get('/{collection}/{id}/{property}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch');
        $group->get('/{id}/{filename}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch-short');
        $group->get('/{collection}/{id}/{property}/{filename}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch');

        // TODO: $group->delete('/{collection}/{id}/{property}/{file}', ImageWorks\ImageWorksClearCacheAction::class)->setName('clear-cache');
    })->add(RobotsTagMiddleware::class);
};
