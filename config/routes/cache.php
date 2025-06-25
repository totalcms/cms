<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Cache\CacheDeleteAction;
use TotalCMS\Action\Cache\CollectionImageCacheDeleteAction;

return function (App $app) {
	$app->group('/cache', function (RouteCollectorProxy $group) {
		$group->delete('', CacheDeleteAction::class)->setName('cache-delete');
		$group->delete('/images', CollectionImageCacheDeleteAction::class)->setName('post-collection-image-cache-delete');
		$group->delete('/collections/{collection}/images', CollectionImageCacheDeleteAction::class)->setName('collection-image-cache-delete');
	});
};
