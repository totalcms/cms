<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action;
use TotalCMS\Action\Collection;
use TotalCMS\Action\Property;
use TotalCMS\Action\Schema;

return function (App $app) {
    $app->group('/collections', function (RouteCollectorProxy $group) {
        // All Collections
        $group->get('', Collection\CollectionListAction::class)->setName('collections-list');

        // Collection
        $group->post('', Collection\CollectionSaveAction::class)->setName('collection-save');
        $group->get('/{collection}', Collection\CollectionFetchAction::class)->setName('collection-fetch');
        $group->put('/{collection}', Collection\CollectionUpdateAction::class)->setName('collection-update');
        $group->patch('/{collection}', Collection\CollectionPatchAction::class)->setName('collection-patch');

        // Collection Schema
        $group->get('/{collection}/schema', Schema\SchemaFetchForCollectionAction::class)->setName('collection-fetch-schema');

        // Collection Index
        $group->get('/{collection}/index', Collection\Index\IndexGetAction::class)->setName('collection-fetch-index');
        $group->put('/{collection}/index', Collection\Index\IndexBuildAction::class)->setName('collection-reindex');

        // Objects
        $group->post('/{collection}', Action\Object\ObjectSaveAction::class)->setName('object-save');
        $group->get('/{collection}/{id}', Action\Object\ObjectFetchAction::class)->setName('object-fetch');
        $group->delete('/{collection}/{id}', Action\Object\ObjectDeleteAction::class)->setName('object-delete');
        $group->put('/{collection}/{id}', Action\Object\ObjectUpdateAction::class)->setName('object-update');
        $group->patch('/{collection}/{id}', Action\Object\ObjectPatchAction::class)->setName('object-patch');
        $group->post('/{collection}/{id}/clone', Action\Object\ObjectCloneAction::class)->setName('object-clone');
        $group->map(['HEAD'], '/{collection}/{id}', Action\Object\ObjectExistsAction::class)->setName('object-exists');

        // Object Property
        $group->put('/{collection}/{id}/{property}', Action\Object\ObjectUpdatePropertyAction::class)->setName('property-update');
        $group->patch('/{collection}/{id}/{property}', Action\Object\ObjectPatchPropertyAction::class)->setName('property-patch');
        // $group->delete('/{collection}/{id}/{property}', Property\PropertyClearAction::class)->setName('property-clear');

        // Property File
        $group->post('/{collection}/{id}/{property}', Property\File\FileSaveAction::class)->setName('property-file-save');
        // $group->put('/{collection}/{id}/{property}/{file}', Property\File\FileUpdateAction::class)->setName('property-file-update-meta');
        // $group->delete('/{collection}/{id}/{property}/{file}', Property\File\FileDeleteAction::class)->setName('property-file-delete');
    });
};
