<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Sitemap;

return function (App $app): void {
	$app->group('/sitemap', function (RouteCollectorProxy $group): void {
		$group->get('/{collection}', Sitemap\SitemapFactoryAction::class)->setName('sitemap-factory');
	});
};
