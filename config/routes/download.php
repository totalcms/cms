<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Download;

return function (App $app) {
    $app->group('/download', function (RouteCollectorProxy $group) {
        // /products/total-cms/brochure -> download a pdf
        // property is name of the file
        // !$group->get('/{collection}/{id}/{property}', Download\DownloadFileAction::class)->setName('download-file');

        // /collection/product/total-cms to get the json data of the

        // /products/total-cms/downloads/total-cms.3.0.zip
        // folder of files
        // !$group->get('/{collection}/{id}/{property}/{file}', Download\DownloadFileFromSetAction::class)->setName('download-file-from-set');
    });
};

// Large file streaming
// https://discourse.slimframework.com/t/slim4-output-buffering-large-files-zip-streaming/4917
