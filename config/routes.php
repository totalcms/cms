<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->options('/', \App\Action\PreflightAction::class);

    // Password protected area
    // $app->group('/users', function (RouteCollectorProxy $group) {
    //     $group->get('', \App\Action\User\UserListAction::class)->setName('user-list');
    //     $group->post('/datatable', \App\Action\User\UserListDataTableAction::class)->setName('user-datatable');
    // })->add(UserAuthMiddleware::class);

    //----------------------------------------------------------------------
    // Collections Route Map
    //----------------------------------------------------------------------
    $app->group('/collections', function (RouteCollectorProxy $group) {
        // All Collections
        $group->get('', \App\Action\Collection\CollectionListAction::class)->setName('collections-get');

        // Collection Schema
        $group->get('/{collection}/schema', \App\Action\Collection\Schema\SchemaGetAction::class)->setName('schema-get');
        $group->post('/{collection}/schema', \App\Action\Collection\Schema\SchemaSaveAction::class)->setName('schema-save'); // Pro Only License

        // Collection Index
        $group->get('/{collection}', \App\Action\Collection\Index\IndexGetAction::class)->setName('collection-get');
        $group->put('/{collection}[/index]', \App\Action\Collection\Index\IndexUpdateAction::class)->setName('collection-reindex');

        // Collection Object
        $group->post('/{collection}', \App\Action\Collection\Object\ObjectSaveAction::class)->setName('object-save');
        $group->get('/{collection}/{id}', \App\Action\Collection\Object\ObjectGetAction::class)->setName('object-get');
        $group->get('/{collection}/{id}/exists', \App\Action\Collection\Object\ObjectExistsAction::class)->setName('object-exists');
        $group->put('/{collection}/{id}', \App\Action\Collection\Object\ObjectUpdateAction::class)->setName('object-update');
        $group->delete('/{collection}/{id}', \App\Action\Collection\Object\ObjectDeleteAction::class)->setName('object-delete');

        // Object Property
        $group->put('/{collection}/{id}/{property}', \App\Action\Collection\Object\Property\PropertyUpdateAction::class)->setName('property-update');
        $group->delete('/{collection}/{id}/{property}/cache', \App\Action\Collection\Object\Property\PropertyClearCacheAction::class)->setName('property-clear-cache');

        // Property File
        $group->post('/{collection}/{id}/{property}', \App\Action\Collection\Object\Property\File\FileSaveAction::class)->setName('property-file-save');
        $group->delete('/{collection}/{id}/{property}/{file}', \App\Action\Collection\Object\Property\File\FileDeleteAction::class)->setName('property-file-delete');
        $group->put('/{collection}/{id}/{property}/{file}', \App\Action\Collection\Object\Property\File\FileUpdateAction::class)->setName('property-file-update-meta');
    });

    //----------------------------------------------------------------------
    // Download Route Map
    //----------------------------------------------------------------------
    $app->group('/download', function (RouteCollectorProxy $group) {
        $group->get('/{collection}/{id}/{property}', \App\Action\Download\DownloadFileAction::class)->setName('download-file');
        $group->get('/{collection}/{id}/{property}/{file}', \App\Action\Download\DownloadFileFromSetAction::class)->setName('download-file-from-set');
    });

    //----------------------------------------------------------------------
    // ImageWorks Route Map
    //----------------------------------------------------------------------
    $app->group('/imageworks', function (RouteCollectorProxy $group) {
        // Allow indexing of images
        header_remove('X-Robots-Tag');

        $group->get('/{collection}/{id}/{property}', \App\Action\ImageWorks\ImageWorksImageGetAction::class)->setName('image-get');
        $group->get('/{collection}/{id}/{property}/{file}', \App\Action\ImageWorks\ImageWorksGalleryImageGetAction::class)->setName('image-gallery-get');
    });

    //----------------------------------------------------------------------
    // Froala File API Route Map - Needs to return specific format
    //----------------------------------------------------------------------
    $app->group('/froala', function (RouteCollectorProxy $group) {
        // {collection} - Collection name
        // {id} - object ID
        // {property} - the name of the froala object property
        // {type} - uploaded file type (image, file, video)
        $group->post('/{collection}/{id}/{property}/{type}', \App\Action\Froala\FroalaUploadFileAction::class)->setName('froala-upload');
        $group->get('/{collection}/{id}/{property}/image', \App\Action\Froala\FroalaGetImagesAction::class)->setName('froala-image-get');
        $group->delete('/{collection}/{id}/{property}/image/{file}', \App\Action\Froala\FroalaDeleteImageAction::class)->setName('froala-image-delete');
    });

    //----------------------------------------------------------------------
    // Import Route Map
    //----------------------------------------------------------------------
    $app->group('/import', function (RouteCollectorProxy $group) {
        $group->post('/{collection}[/factory]', \App\Action\Import\ImportFactoryAction::class)->setName('import-factory');
        $group->post('/{collection}/yaml', \App\Action\Import\ImportYAMLAction::class)->setName('import-yaml');
        $group->post('/{collection}/json', \App\Action\Import\ImportJSONAction::class)->setName('import-json');
        $group->post('/{collection}/csv', \App\Action\Import\ImportCSVAction::class)->setName('import-csv');
        $group->post('/{collection}/rss', \App\Action\Import\ImportRSSAction::class)->setName('import-rss');
        $group->post('/{collection}/url', \App\Action\Import\ImportURLAction::class)->setName('import-url');
        $group->post('/{collection}/wordpress', \App\Action\Import\ImportWordpressAction::class)->setName('import-wordpress');
        $group->post('/{collection}/tumblr', \App\Action\Import\ImportTumblrAction::class)->setName('import-tumblr');
    });

    //----------------------------------------------------------------------
    // Templates Route Map
    //----------------------------------------------------------------------
    $app->group('/templates', function (RouteCollectorProxy $group) {
        $group->get('/{type}/{template}', \App\Action\Template\TemplateGetByTypeAction::class)->setName('template-get-type');
    });
};
