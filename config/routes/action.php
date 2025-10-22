<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Mailer\SendEmailAction;
use TotalCMS\Middleware\Security\RateLimitMiddleware;

return function (App $app): void {
	$app->group('/action', function (RouteCollectorProxy $group): void {
		// Email sending endpoint with rate limiting
		$group->post('/mailer', SendEmailAction::class)->setName('action-send-email')->add(RateLimitMiddleware::class);
	});
};
