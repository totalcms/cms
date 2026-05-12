<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Sitemap;

return function (RouteCollectorProxyInterface $app): void {
	// Sitemap index — accessible at both /sitemap and /sitemap.xml
	// (the .xml form is what crawlers expect to declare in robots.txt).
	$app->get('/sitemap', Sitemap\SitemapIndexAction::class)->setName('sitemap-index');
	$app->get('/sitemap.xml', Sitemap\SitemapIndexAction::class)->setName('sitemap-index-xml');

	$app->group('/sitemap', function (RouteCollectorProxy $group): void {
		// `-pages` (leading dash convention) keeps the builder pages sitemap from
		// colliding with a user collection literally named "pages".
		$group->get('/-pages', Sitemap\PageSitemapAction::class)->setName('sitemap-pages');
		$group->get('/{collection}', Sitemap\SitemapFactoryAction::class)->setName('sitemap-factory');
	});
};
