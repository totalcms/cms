<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Mailer\BulkMailerAction;
use TotalCMS\Action\Mailer\BulkMailerPreviewAction;
use TotalCMS\Action\Mailer\BulkObjectOptionsAction;
use TotalCMS\Action\Mailer\SendEmailAction;
use TotalCMS\Middleware\License\BulkMailerEditionMiddleware;
use TotalCMS\Middleware\Security\RateLimitMiddleware;

return function (App $app): void {
	$app->group('/action', function (RouteCollectorProxy $group): void {
		// Email sending endpoint with rate limiting
		$group->post('/mailer', SendEmailAction::class)->setName('action-send-email')->add(RateLimitMiddleware::class);

		// Bulk mailer endpoints (Pro edition only)
		$group->post('/mailer/bulk', BulkMailerAction::class)->setName('action-bulk-mailer')->add(BulkMailerEditionMiddleware::class);
		$group->post('/mailer/bulk/preview', BulkMailerPreviewAction::class)->setName('action-bulk-mailer-preview')->add(BulkMailerEditionMiddleware::class);
		$group->get('/mailer/bulk/objects', BulkObjectOptionsAction::class)->setName('action-bulk-mailer-objects')->add(BulkMailerEditionMiddleware::class);
	});
};
