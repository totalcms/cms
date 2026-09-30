<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Mailer\BulkMailerAction;
use TotalCMS\Action\Mailer\BulkMailerHistoryAction;
use TotalCMS\Action\Mailer\BulkMailerPreviewAction;
use TotalCMS\Action\Mailer\BulkObjectOptionsAction;
use TotalCMS\Action\Mailer\SendEmailAction;
use TotalCMS\Middleware\Access\MailerAccessMiddleware;
use TotalCMS\Middleware\Auth\DualAuthMiddleware;
use TotalCMS\Middleware\License\BulkMailerEditionMiddleware;
use TotalCMS\Middleware\Security\RateLimitMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/action', function (RouteCollectorProxy $group): void {
		// Email sending endpoint with rate limiting
		$group->post('/mailer', SendEmailAction::class)->setName('action-send-email')->add(RateLimitMiddleware::class);

		// Bulk mailer endpoints: admin tools, so signed-in (or API key) users
		// with the mailer permission only, on the Pro edition. The send
		// endpoint above stays public because front-end form actions call it.
		$group->group('/mailer/bulk', function (RouteCollectorProxy $bulk): void {
			$bulk->post('', BulkMailerAction::class)->setName('action-bulk-mailer');
			$bulk->post('/preview', BulkMailerPreviewAction::class)->setName('action-bulk-mailer-preview');
			$bulk->get('/objects', BulkObjectOptionsAction::class)->setName('action-bulk-mailer-objects');
			$bulk->get('/history', BulkMailerHistoryAction::class)->setName('action-bulk-mailer-history');
		})
			->add(BulkMailerEditionMiddleware::class)
			->add(MailerAccessMiddleware::class)
			->add(DualAuthMiddleware::class);
	});
};
