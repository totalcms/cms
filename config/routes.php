<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->options('/', \App\Action\PreflightAction::class);

    //----------------------------------------------------------------------
    // Schemas Route Map
    //----------------------------------------------------------------------
    $app->group('/schemas', function (RouteCollectorProxy $group) {
        // Collection Schema
        $group->get('/{collection}', \App\Action\Collection\Schema\SchemaFetchAction::class)->setName('schema-fetch');
        $group->post('/{collection}', \App\Action\Collection\Schema\SchemaSaveAction::class)->setName('schema-save'); // Pro Only License
    });

    //----------------------------------------------------------------------
    // Collections Route Map
    //----------------------------------------------------------------------
    $app->group('/collections', function (RouteCollectorProxy $group) {
        // All Collections
        $group->get('', \App\Action\Collection\CollectionListAction::class)->setName('collections-list');
        $group->post('', \App\Action\Collection\CollectionSaveAction::class)->setName('collection-save');

        // Collection
        $group->get('/{collection}', \App\Action\Collection\Index\IndexFetchAction::class)->setName('collection-fetch');
        $group->put('/{collection}', \App\Action\Collection\Index\IndexUpdateAction::class)->setName('collection-reindex');
        $group->post('/{collection}', \App\Action\Collection\Object\ObjectSaveAction::class)->setName('object-save');

        // Object
        $group->head('/{collection}/{id}', \App\Action\Collection\Object\ObjectExistsAction::class)->setName('object-exists');
        $group->get('/{collection}/{id}', \App\Action\Collection\Object\ObjectFetchAction::class)->setName('object-fetch');
        $group->put('/{collection}/{id}', \App\Action\Collection\Object\ObjectUpdateAction::class)->setName('object-update');
        $group->delete('/{collection}/{id}', \App\Action\Collection\Object\ObjectDeleteAction::class)->setName('object-delete');

        // Object Property
        $group->put('/{collection}/{id}/{property}', \App\Action\Collection\Object\Property\PropertyUpdateAction::class)->setName('property-update');
        $group->post('/{collection}/{id}/{property}', \App\Action\Collection\Object\Property\File\FileSaveAction::class)->setName('property-file-save');

        // Property File
        $group->put('/{collection}/{id}/{property}/{file}', \App\Action\Collection\Object\Property\File\FileUpdateAction::class)->setName('property-file-update-meta');
        $group->delete('/{collection}/{id}/{property}/{file}', \App\Action\Collection\Object\Property\File\FileDeleteAction::class)->setName('property-file-delete');
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

        $group->get('/{collection}/{id}/{property}', \App\Action\ImageWorks\ImageWorksImageFetchAction::class)->setName('image-fetch');
        // Its better to require the full filename for SEO since that contains an image file extension
        $group->get('/{collection}/{id}/{property}/{file}', \App\Action\ImageWorks\ImageWorksGalleryImageFetchAction::class)->setName('gallery-fetch');
        $group->delete('/{collection}/{id}/{property}/{file}', \App\Action\ImageWorks\ImageWorksClearCacheAction::class)->setName('clear-cache');
    });

    //----------------------------------------------------------------------
    // Froala File API Route Map - Needs to return specific format
    //----------------------------------------------------------------------
    $app->group('/froala', function (RouteCollectorProxy $group) {
        // {id} - object ID
        $group->post('/depot/{id}', \App\Action\Froala\FroalaUploadFileAction::class)->setName('froala-upload');
        $group->get('/gallery/{id}', \App\Action\Froala\FroalaUploadFileAction::class)->setName('froala-image-fetch');
        $group->post('/gallery/{id}', \App\Action\Froala\FroalaUploadFileAction::class)->setName('froala-upload');
        $group->delete('/gallery/{id}', \App\Action\Froala\FroalaDeleteImageAction::class)->setName('froala-image-delete');
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
    });

    //----------------------------------------------------------------------
    // Templates Route Map
    //----------------------------------------------------------------------
    // $app->group('/templates', function (RouteCollectorProxy $group) {
        // $group->get('/{type}/{template}', \App\Action\Template\TemplateFetchByTypeAction::class)->setName('template-fetch-type');
    // });
};
