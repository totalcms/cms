<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Cache\CacheDeleteAction;
use TotalCMS\Action\Cache\CollectionImageCacheDeleteAction;
use TotalCMS\Action\Cache\DevModeDisableAction;
use TotalCMS\Action\Cache\DevModeEnableAction;
use TotalCMS\Action\Cache\DevModeStatusAction;

return function (App $app): void {
	$app->group('/cache', function (RouteCollectorProxy $group): void {
		$group->delete('', CacheDeleteAction::class)->setName('cache-delete');
		$group->delete('/images', CollectionImageCacheDeleteAction::class)->setName('post-collection-image-cache-delete');
		$group->delete('/collections/{collection}/images', CollectionImageCacheDeleteAction::class)->setName('collection-image-cache-delete');
		$group->get('/devmode', DevModeStatusAction::class)->setName('cache-devmode-status');
		$group->post('/devmode', DevModeEnableAction::class)->setName('cache-devmode-enable');
		$group->delete('/devmode', DevModeDisableAction::class)->setName('cache-devmode-disable');
	});
};
