<?php

use Slim\App;
use TotalCMS\Action\Cache\CacheDeleteAction;
use TotalCMS\Action\Cache\CollectionImageCacheDeleteAction;

return function (App $app) {
	$app->delete('/cache', CacheDeleteAction::class)->setName('cache-delete');
	$app->delete('/cache/images', CollectionImageCacheDeleteAction::class)->setName('post-collection-image-cache-delete');
	$app->delete('/cache/collections/{collection}/images', CollectionImageCacheDeleteAction::class)->setName('collection-image-cache-delete');
};
