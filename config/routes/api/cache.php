<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Cache\AllCollectionImageCacheDeleteAction;
use TotalCMS\Action\Cache\CacheDeleteAction;
use TotalCMS\Action\Cache\CollectionImageCacheDeleteAction;
use TotalCMS\Action\Cache\DevModeDisableAction;
use TotalCMS\Action\Cache\DevModeEnableAction;
use TotalCMS\Action\Cache\DevModeStatusAction;
use TotalCMS\Action\Cache\WatermarkCacheDeleteAction;
use TotalCMS\Middleware\Auth\AuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/cache', function (RouteCollectorProxy $group): void {
		$group->delete('', CacheDeleteAction::class)->setName('cache-delete');
		$group->delete('/images', CollectionImageCacheDeleteAction::class)->setName('post-collection-image-cache-delete');
		$group->delete('/images/all', AllCollectionImageCacheDeleteAction::class)->setName('all-collection-image-cache-delete');
		$group->delete('/watermarks', WatermarkCacheDeleteAction::class)->setName('watermark-cache-delete');
		$group->delete('/collections/{collection}/images', CollectionImageCacheDeleteAction::class)->setName('collection-image-cache-delete');
		$group->get('/devmode', DevModeStatusAction::class)->setName('cache-devmode-status');
		$group->post('/devmode', DevModeEnableAction::class)->setName('cache-devmode-enable');
		$group->delete('/devmode', DevModeDisableAction::class)->setName('cache-devmode-disable');
	})->add(AuthMiddleware::class);
};
