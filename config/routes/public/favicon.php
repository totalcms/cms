<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Seo\FaviconAction;

return function (RouteCollectorProxyInterface $app): void {
	// Both served from the Site SEO record's Icon fields. Browsers request
	// /favicon.ico unprompted, so it lives at the root, not under /api.
	$app->get('/favicon.{format:ico|svg}', FaviconAction::class)->setName('favicon');
};
