<?php

use App\Action\Collection;
use App\Action\Object as ObjectAction;
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
        $group->post('/{collection}', ObjectAction\ObjectSaveAction::class)->setName('object-save');

        // Object Exists in a collection
        $group->map(['HEAD'], '/{collection}/{id}', ObjectAction\ObjectExistsAction::class)->setName('object-exists');

        // Get collection object by id
        $group->get('/{collection}/{id}', ObjectAction\ObjectFetchAction::class)->setName('object-fetch');
        $group->delete('/{collection}/{id}', ObjectAction\ObjectDeleteAction::class)->setName('object-delete');
        $group->put('/{collection}/{id}', ObjectAction\ObjectUpdateAction::class)->setName('object-update');

        // Object Property
        // !$group->put('/{collection}/{id}/{property}', ObjectAction\Property\PropertyUpdateAction::class)
        // !->setName('property-update');
        // !$group->post('/{collection}/{id}/{property}', ObjectAction\Property\File\FileSaveAction::class)
        // !->setName('property-file-save');

        // Property File
        // !$group->put('/{collection}/{id}/{property}/{file}', ObjectAction\Property\File\FileUpdateAction::class)
        // !->setName('property-file-update-meta');

        // !$group->delete('/{collection}/{id}/{property}/{file}', ObjectAction\Property\File\FileDeleteAction::class)
        // !->setName('property-file-delete');
    });
};
