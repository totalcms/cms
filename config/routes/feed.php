<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Feed;

return function (App $app): void {
	$app->group('/feed', function (RouteCollectorProxy $group): void {
		$group->get('/rss/{collection}', Feed\RssFeedAction::class)->setName('rss-feed');
		// $group->get('/activitypub/{collection}', Sitemap\RssFeedAction::class)->setName('actpub-feed');
		// $group->get('/applenews/{collection}', Sitemap\RssFeedAction::class)->setName('applenews-feed');
		// $group->get('/jsonfeed/{collection}', Sitemap\RssFeedAction::class)->setName('json-feed');
	});
};
