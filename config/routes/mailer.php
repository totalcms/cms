<?php

use Slim\App;
use TotalCMS\Action\Mailer\SendEmailAction;
use TotalCMS\Middleware\RateLimitMiddleware;

return function (App $app): void {
	// Email sending endpoint with rate limiting
	$app->post('/action/email', SendEmailAction::class)
		->setName('action-send-email')
		->add(RateLimitMiddleware::class);
};
