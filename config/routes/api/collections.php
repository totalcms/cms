<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action;
use TotalCMS\Action\Collection;
use TotalCMS\Action\Property;
use TotalCMS\Action\Schema;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Middleware\Access\CollectionMetaAccessMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Middleware\License\CollectionEditionMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;
use TotalCMS\Middleware\Security\ExternalCorsMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/collections', function (RouteCollectorProxy $group): void {
		// Collection
		$group->post('', Collection\CollectionSaveAction::class)->setName('collection-save');
		$group->delete('/{collection}', Collection\CollectionDeleteAction::class)->setName('collection-delete');
		$group->put('/{collection}', Collection\CollectionUpdateAction::class)->setName('collection-update');
		$group->patch('/{collection}', Collection\CollectionPatchAction::class)->setName('collection-patch');
	})->add(CollectionEditionMiddleware::class)
		->add(CollectionMetaAccessMiddleware::class)
		->add(AuthMiddleware::class);

	$app->group('/collections', function (RouteCollectorProxy $group): void {
		// All Collections
		$group->get('', Collection\CollectionListAction::class)->setName('collections-list');
		// Collection
		$group->get('/{collection}', Collection\CollectionFetchAction::class)->setName('collection-fetch');
		$group->map(['HEAD'], '/{collection}', Collection\CollectionExistsAction::class)->setName('collection-exists');
		// Collection Schema
		$group->get('/{collection}/schema', Schema\SchemaFetchForCollectionAction::class)->setName('collection-fetch-schema');
	})->add(CollectionEditionMiddleware::class)
		->add(CollectionMetaAccessMiddleware::class)
		->add(DualAuthMiddleware::class)
		->add(ExternalCorsMiddleware::class);

	$app->group('/collections', function (RouteCollectorProxy $group): void {
		// Collection Query (paginated)
		$group->get('/{collection}/query', Collection\Index\IndexQueryAction::class)->setName('collection-query');

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
		$group->post('/{collection}/{id}/{property}/increment[/{amount}]', Action\Object\ObjectPropertyIncrementAction::class)->setName('property-increment');
		$group->post('/{collection}/{id}/{property}/decrement[/{amount}]', Action\Object\ObjectPropertyDecrementAction::class)->setName('property-decrement');

		// Object Property Meta — `{path:.+}` covers both gallery item meta and
		// card-nested property updates. The action dispatches on filesystem state.
		$group->put('/{collection}/{id}/{property}/{path:.+}', Action\Object\ObjectUpdatePropertyMetaAction::class)->setName('property-meta-update');
		$group->patch('/{collection}/{id}/{property}/{path:.+}', Action\Object\ObjectPatchPropertyMetaAction::class)->setName('property-meta-patch');

		// Deck Items (for deck properties)
		$group->post('/{collection}/{id}/{property}/deck', Property\Deck\DeckItemCreateAction::class)->setName('deck-item-create');
		$group->get('/{collection}/{id}/{property}/deck/{itemId}', Property\Deck\DeckItemFetchAction::class)->setName('deck-item-fetch');
		$group->put('/{collection}/{id}/{property}/deck/{itemId}', Property\Deck\DeckItemUpdateAction::class)->setName('deck-item-update');
		$group->delete('/{collection}/{id}/{property}/deck/{itemId}', Property\Deck\DeckItemDeleteAction::class)->setName('deck-item-delete');

		// Property File
		$group->post('/{collection}/{id}/{property}', Property\File\FileSaveAction::class)->setName('property-file-save');
		$group->post('/{collection}/{id}/{property}/folder', Property\File\FolderSaveAction::class)->setName('property-folder-save');
		$group->put('/{collection}/{id}/{property}/folder/rename', Property\File\FolderRenameAction::class)->setName('property-folder-rename');
		$group->delete('/{collection}/{id}/{property}/cache', Property\PropertyClearCacheAction::class)->setName('property-clear-cache');
		// `{path:.+}/cache` covers both cases that look identical at the URL level:
		// (1) gallery file cache (path is a filename — clears `prop/.cache/{name}/`)
		// (2) nested property cache (card child or deck-item child — clears `prop/{key}/.cache/`)
		// PropertyFileClearCacheAction dispatches based on filesystem state.
		// MUST register before the catch-all `{path:.+}` delete below — FastRoute
		// chunks routes in registration order and the bare greedy pattern would
		// otherwise swallow URLs ending in `/cache`.
		$group->delete('/{collection}/{id}/{property}/{path:.+}/cache', Property\PropertyFileClearCacheAction::class)->setName('property-file-clear-cache');
		// `{path:.+}` covers gallery file delete AND nested property delete.
		// FileDeleteAction dispatches based on filesystem state.
		$group->delete('/{collection}/{id}/{property}/{path:.+}', Property\File\FileDeleteAction::class)->setName('property-file-delete');
		$group->put('/{collection}/{id}/{property}/{name}/move', Property\File\FileMoveAction::class)->setName('property-file-move');

		// Nested file save — children of card (and later deck) fields. The
		// `{path:.+}` greedy segment captures one or more child-key/item-id
		// segments and is dispatched after the literal-prefix routes above
		// (folder, deck, increment, decrement) which take precedence.
		$group->post('/{collection}/{id}/{property}/{path:.+}', Property\File\FileSaveAction::class)->setName('property-file-save-nested');
	})->add(NoCacheMiddleware::class)
		->add(CollectionEditionMiddleware::class)
		->add(CollectionAccessMiddleware::class)
		->add(DualAuthMiddleware::class)
		->add(ExternalCorsMiddleware::class);
};
