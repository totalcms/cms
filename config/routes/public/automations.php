<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Automation\AutomationWebhookAction;
use TotalCMS\Middleware\Automation\AutomationWebhookMiddleware;

/**
 * Root-level automation webhook endpoint: POST /automations/{id}. POST only —
 * a webhook is a trigger, not a queryable API. Per-trigger auth (apiKey /
 * none) is enforced by AutomationWebhookMiddleware. Stacks installs need the
 * parallel `^automations/` rewrite (see docs/operations/apache.md), same as
 * MCP / OAuth.
 */
return function (RouteCollectorProxyInterface $app): void {
	$app->post('/automations/{id}', AutomationWebhookAction::class)
		->add(AutomationWebhookMiddleware::class)
		->setName('automation-webhook');
};
