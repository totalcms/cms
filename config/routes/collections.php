<?php

use App\Action as Action;
use App\Action\Collection;
use App\Action\Property;
use App\Action\Schema;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->group('/collections', function (RouteCollectorProxy $group) {
        // All Collections

        $group->get('', Collection\CollectionListAction::class)->setName('collections-list');
        $group->post('', Collection\CollectionSaveAction::class)->setName('collection-save');

        // Single Collection

        // Get schema of a collection
        $group->get('/{collection}/schema', Schema\SchemaFetchForCollectionAction::class)
            ->setName('collection-fetch-schema');

        // Get index data of the collection
        $group->get('/{collection}', Collection\Index\IndexGetAction::class)->setName('collection-fetch');

        // Reindex collection
        $group->put('/{collection}', Collection\Index\IndexBuildAction::class)->setName('collection-reindex');

        // Create a new obejct in a collection
        $group->post('/{collection}', Action\Object\ObjectSaveAction::class)->setName('object-save');

        // Object Exists in a collection
        $group->map(['HEAD'], '/{collection}/{id}', Action\Object\ObjectExistsAction::class)->setName('object-exists');

        // Get collection object by id
        $group->get('/{collection}/{id}', Action\Object\ObjectFetchAction::class)->setName('object-fetch');
        $group->delete('/{collection}/{id}', Action\Object\ObjectDeleteAction::class)->setName('object-delete');
        $group->put('/{collection}/{id}', Action\Object\ObjectUpdateAction::class)->setName('object-update');

        // Object Property
        // $group->put('/{collection}/{id}/{property}', Property\PropertyUpdateAction::class)
        //     ->setName('property-update');

        // Property File
        $group->post('/{collection}/{id}/{property}', Property\File\FileSaveAction::class)
            ->setName('property-file-save');
        // $group->put('/{collection}/{id}/{property}/{file}', Property\File\FileUpdateAction::class)
        //     ->setName('property-file-update-meta');
        // $group->delete('/{collection}/{id}/{property}/{file}', Property\File\FileDeleteAction::class)
        //     ->setName('property-file-delete');
    });
};
