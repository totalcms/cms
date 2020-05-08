<?php

use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Slim\App;
use App\Action\PreflightAction;
use App\Middleware\UserAuthMiddleware;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/', \App\Action\HomeAction::class);
    $app->options('/', PreflightAction::class);

    // Password protected area
    // $app->group('/users', function (RouteCollectorProxy $group) {
    //     $group->get('', \App\Action\User\UserListAction::class)->setName('user-list');
    //     $group->post('/datatable', \App\Action\User\UserListDataTableAction::class)->setName('user-datatable');
    // })->add(UserAuthMiddleware::class);

    //----------------------------------------------------------------------
    // Import Route Map
    //----------------------------------------------------------------------
    $app->group('/import', function (RouteCollectorProxy $group) {
        $group->post('/{collection}[/factory]', \App\Action\Import\ImportCollectionFactoryAction::class);
        $group->post('/{collection}/yaml', \App\Action\Import\ImportCollectionYAMLAction::class);
        $group->post('/{collection}/json', \App\Action\Import\ImportCollectionJSONAction::class);
        $group->post('/{collection}/csv', \App\Action\Import\ImportCollectionCSVAction::class);
        $group->post('/{collection}/rss', \App\Action\Import\ImportCollectionRSSAction::class);
        $group->post('/{collection}/url', \App\Action\Import\ImportCollectionURLAction::class);
        $group->post('/{collection}/wordpress', \App\Action\Import\ImportCollectionWordpressAction::class);
        $group->post('/{collection}/tumblr', \App\Action\Import\ImportCollectionTumblrAction::class);
    });

    //----------------------------------------------------------------------
    // Templates Route Map
    //----------------------------------------------------------------------
    $app->group('/templates', function (RouteCollectorProxy $group) {
        $group->get('/{type}/{template}', \App\Action\Template\TemplateGetByTypeAction::class);
    });

    //----------------------------------------------------------------------
    // Collections Route Map
    //----------------------------------------------------------------------
    $app->group('/collections', function (RouteCollectorProxy $group) {
        // Collection Schema
        $group->get('/{collection}/schema', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->post('/{collection}/schema', \App\Action\Collection\CollectionGetSchemaAction::class);

        // Collection Index
        $group->put('/{collection}/index', \App\Action\Collection\CollectionGetSchemaAction::class);

        // Collection Objects
        $group->get('', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->get('/{collection}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->get('/{collection}/{id}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->get('/{collection}/{id}/exists', \App\Action\Collection\CollectionGetSchemaAction::class);

        $group->post('/{collection}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->post('/{collection}/{id}/{property}', \App\Action\Collection\CollectionGetSchemaAction::class);

        $group->put('/{collection}/{id}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->put('/{collection}/{id}/{property}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->put('/{collection}/{id}/{property}/{file}', \App\Action\Collection\CollectionGetSchemaAction::class);

        $group->delete('/{collection}/{id}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->delete('/{collection}/{id}/{property}/{file}', \App\Action\Collection\CollectionGetSchemaAction::class);
        // $group->delete('/{collection}/{id}/{property}', \App\Action\Collection\CollectionGetSchemaAction::class);
        $group->delete('/{collection}/{id}/{property}/cache', \App\Action\Collection\CollectionGetSchemaAction::class);
    });

    //----------------------------------------------------------------------
    // Download Route Map
    //----------------------------------------------------------------------
    $app->group('/download', function (RouteCollectorProxy $group) {
        $group->get('/{collection}/{id}/{property}', \App\Action\Download\DownloadFileAction::class);
        $group->get('/{collection}/{id}/{property}/{file}', \App\Action\Download\DownloadFileFromSetAction::class);
    });

    //----------------------------------------------------------------------
    // ImageWorks Route Map
    //----------------------------------------------------------------------
    $app->group('/imageworks', function (RouteCollectorProxy $group) {
        // Allow indexing of images
        header_remove('X-Robots-Tag');

        $group->get('/{collection}/{id}/{property}', \App\Action\ImageWorks\ImageWorksGetImageAction::class);
        $group->get('/{collection}/{id}/{property}/{file}', \App\Action\ImageWorks\ImageWorksGetGalleryImageAction::class);
    });

    //----------------------------------------------------------------------
    // Froala File API Route Map - Needs to return specific format
    //----------------------------------------------------------------------
    $app->group('/froala', function (RouteCollectorProxy $group) {
        // {collection} - Collection name
        // {id} - object ID
        // {property} - the name of the froala object property
        // {type} - uploaded file type (image, file, video)
        $group->post('/{collection}/{id}/{property}/{type}', \App\Action\Froala\FroalaUploadFileAction::class);
        $group->get('/{collection}/{id}/{property}/image', \App\Action\Froala\FroalaGetImagesAction::class);
        $group->delete('/{collection}/{id}/{property}/image/{file}', \App\Action\Froala\FroalaDeleteImageAction::class);
    });
};
