<?php

use App\Action\ImageWorks;
use App\Middleware\RobotsTagMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/imageworks', function (RouteCollectorProxy $group) {
        $group->get('/{collection}/{id}/{property}', ImageWorks\ImageWorksImageFetchAction::class)
            ->setName('image-fetch');

        // It's better to require the full filename for SEO since that contains an image file extension
        $group->get('/{collection}/{id}/{property}/{file}', ImageWorks\ImageWorksGalleryFetchAction::class)
            ->setName('gallery-fetch');

        $group->delete('/{collection}/{id}/{property}/{file}', ImageWorks\ImageWorksClearCacheAction::class)
            ->setName('clear-cache');
    })->add(RobotsTagMiddleware::class);
};
