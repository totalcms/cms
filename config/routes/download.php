<?php

use App\Action\Download;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/download', function (RouteCollectorProxy $group) {
        $group->get('/{collection}/{id}/{property}', Download\DownloadFileAction::class)
            ->setName('download-file');

        $group->get('/{collection}/{id}/{property}/{file}', Download\DownloadFileFromSetAction::class)
            ->setName('download-file-from-set');
    });
};