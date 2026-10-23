<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Extension\ExtensionInstallAction;
use TotalCMS\Action\Extension\ExtensionRemoveAction;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Auth\SessionOnlyMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	// Install from an uploaded zip / remove a user extension. Slim runs the
	// last-added middleware first: AuthMiddleware authenticates the session and
	// validates CSRF, SessionOnlyMiddleware rejects API-key / OAuth bearer
	// callers, then AdminOnlyMiddleware requires a super admin. DualAuth is
	// deliberately not used: it admits API keys, and access middleware skips
	// the admin check for them, so a content-write key could install code.
	$app->group('/extensions', function (RouteCollectorProxy $group): void {
		$group->post('/install', ExtensionInstallAction::class)->setName('api-extension-install');
		$group->post('/{extension:[a-z0-9-]+/[a-z0-9-]+}/remove', ExtensionRemoveAction::class)->setName('api-extension-remove');
	})->add(AdminOnlyMiddleware::class)->add(SessionOnlyMiddleware::class)->add(AuthMiddleware::class);
};
