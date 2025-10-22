<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action;
use TotalCMS\Action\Collection;
use TotalCMS\Action\Property;
use TotalCMS\Action\Schema;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\CollectionMetaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;

return function (App $app): void {
	$app->group('/collections', function (RouteCollectorProxy $group): void {
		// Collection
		$group->post('', Collection\CollectionSaveAction::class)->setName('collection-save');
		$group->delete('/{collection}', Collection\CollectionDeleteAction::class)->setName('collection-delete');
		$group->put('/{collection}', Collection\CollectionUpdateAction::class)->setName('collection-update');
		$group->patch('/{collection}', Collection\CollectionPatchAction::class)->setName('collection-patch');
	})->add(CollectionMetaAccessMiddleware::class)
		->add(AuthMiddleware::class);

	$app->group('/collections', function (RouteCollectorProxy $group): void {
		// All Collections
		$group->get('', Collection\CollectionListAction::class)->setName('collections-list');
		// Collection
		$group->get('/{collection}', Collection\CollectionFetchAction::class)->setName('collection-fetch');
		$group->map(['HEAD'], '/{collection}', Collection\CollectionExistsAction::class)->setName('collection-exists');
		// Collection Schema
		$group->get('/{collection}/schema', Schema\SchemaFetchForCollectionAction::class)->setName('collection-fetch-schema');
	})->add(CollectionMetaAccessMiddleware::class)
		->add(DualAuthMiddleware::class);

	$app->group('/collections', function (RouteCollectorProxy $group): void {
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
		$group->delete('/{collection}/{id}/{property}', Action\Object\ObjectDeletePropertyAction::class)->setName('property-delete');

		// Object Property Meta
		$group->put('/{collection}/{id}/{property}/{name}', Action\Object\ObjectUpdatePropertyMetaAction::class)->setName('property-meta-update');
		$group->patch('/{collection}/{id}/{property}/{name}', Action\Object\ObjectPatchPropertyMetaAction::class)->setName('property-meta-patch');

		// Deck Items (for deck properties)
		$group->post('/{collection}/{id}/{property}/deck', Property\Deck\DeckItemCreateAction::class)->setName('deck-item-create');
		$group->get('/{collection}/{id}/{property}/deck/{itemId}', Property\Deck\DeckItemFetchAction::class)->setName('deck-item-fetch');
		$group->put('/{collection}/{id}/{property}/deck/{itemId}', Property\Deck\DeckItemUpdateAction::class)->setName('deck-item-update');
		$group->delete('/{collection}/{id}/{property}/deck/{itemId}', Property\Deck\DeckItemDeleteAction::class)->setName('deck-item-delete');

		// Property File
		$group->post('/{collection}/{id}/{property}', Property\File\FileSaveAction::class)->setName('property-file-save');
		$group->post('/{collection}/{id}/{property}/folder', Property\File\FolderSaveAction::class)->setName('property-folder-save');
		$group->delete('/{collection}/{id}/{property}/cache', Property\PropertyClearCacheAction::class)->setName('property-clear-cache');
		$group->delete('/{collection}/{id}/{property}/{name}', Property\File\FileDeleteAction::class)->setName('property-file-delete');
		$group->delete('/{collection}/{id}/{property}/{name}/cache', Property\PropertyFileClearCacheAction::class)->setName('property-file-clear-cache');
		$group->put('/{collection}/{id}/{property}/{name}/move', Property\File\FileMoveAction::class)->setName('property-file-move');
	})->add(CollectionAccessMiddleware::class)
		->add(DualAuthMiddleware::class);
};
