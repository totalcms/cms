<?php

use App\Action\Collection;
use App\Action\Schema;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/collections', function (RouteCollectorProxy $group) {
        // All Collections
        $group->get('', Collection\CollectionListAction::class)->setName('collections-list');
        $group->post('', Collection\CollectionSaveAction::class)->setName('collection-save');

        // Collection
        // Get name of the collection
        $group->get('/{collection}', Collection\Index\IndexGetAction::class)->setName('collection-fetch');

        // Get schema of a collection
        $group->get('/{collection}/schema', Schema\SchemaFetchForCollectionAction::class)->setName('collection-fetch-schema');

        // Reindex collection
        $group->put('/{collection}', Collection\Index\IndexUpdateAction::class)->setName('collection-reindex');

        // Create a new obejct in a collection
        $group->post('/{collection}', Collection\Object\ObjectSaveAction::class)->setName('object-save');

        // Object Exists in a collection
        $group->map(['HEAD'], '/{collection}/{id}', Collection\Object\ObjectExistsAction::class)->setName('object-exists');

        // Get collection object by id
        $group->get('/{collection}/{id}', Collection\Object\ObjectFetchAction::class)->setName('object-fetch');
        $group->put('/{collection}/{id}', Collection\Object\ObjectUpdateAction::class)->setName('object-update');
        $group->delete('/{collection}/{id}', Collection\Object\ObjectDeleteAction::class)->setName('object-delete');

        // Object Property
        $group->put('/{collection}/{id}/{property}', Collection\Object\Property\PropertyUpdateAction::class)
            ->setName('property-update');
        $group->post('/{collection}/{id}/{property}', Collection\Object\Property\File\FileSaveAction::class)
            ->setName('property-file-save');

        // Property File
        $group->put('/{collection}/{id}/{property}/{file}', Collection\Object\Property\File\FileUpdateAction::class)
            ->setName('property-file-update-meta');

        $group->delete('/{collection}/{id}/{property}/{file}', Collection\Object\Property\File\FileDeleteAction::class)
            ->setName('property-file-delete');
    });
};
