<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Admin\OAuth\OAuthClientCreateAction;
use TotalCMS\Action\Admin\OAuth\OAuthClientDeleteAction;
use TotalCMS\Action\Admin\OAuth\OAuthGrantRevokeAction;
use TotalCMS\Middleware\Access\AdminOnlyMiddleware;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\License\OAuthEditionMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/oauth-clients', function (RouteCollectorProxy $group): void {
		$group->post('', OAuthClientCreateAction::class)->setName('oauth-client-create');
		$group->delete('/{id}', OAuthClientDeleteAction::class)->setName('oauth-client-delete');
	})->add(OAuthEditionMiddleware::class)
		->add(AdminOnlyMiddleware::class)
		->add(AuthMiddleware::class);

	$app->group('/oauth-grants', function (RouteCollectorProxy $group): void {
		$group->delete('/{id}', OAuthGrantRevokeAction::class)->setName('oauth-grant-revoke');
	})->add(OAuthEditionMiddleware::class)
		->add(AdminOnlyMiddleware::class)
		->add(AuthMiddleware::class);
};
