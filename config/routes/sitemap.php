<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Sitemap;

return function (App $app) {
	$app->group('/sitemap', function (RouteCollectorProxy $group) {
		$group->get('/{collection}', Sitemap\SitemapFactoryAction::class)->setName('sitemap-factory');
	});
};
