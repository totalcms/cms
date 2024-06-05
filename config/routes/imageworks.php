<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\ImageWorks;
use TotalCMS\Middleware\RobotsTagMiddleware;

return function (App $app) {
    $app->group('/imageworks', function (RouteCollectorProxy $group) {
        $group->get('/{id}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch-short');
        $group->get('/{collection}/{id}/{property}.{format}', ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch');
        $group->get('/{id}/{filename}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch-short');
        $group->get('/{collection}/{id}/{property}/first', ImageWorks\ImageWorksGalleryFetchFirstAction::class)->setName('gallery-image-fetch-first');
        $group->get('/{collection}/{id}/{property}/last', ImageWorks\ImageWorksGalleryFetchLastAction::class)->setName('gallery-image-fetch-last');
        $group->get('/{collection}/{id}/{property}/random', ImageWorks\ImageWorksGalleryFetchRandomAction::class)->setName('gallery-image-fetch-random');
        $group->get('/{collection}/{id}/{property}/{filename}.{format}', ImageWorks\ImageWorksGalleryFetchAction::class)->setName('gallery-image-fetch');
    })->add(RobotsTagMiddleware::class);
};
