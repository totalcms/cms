<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Auth;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\License\PasskeyEditionMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$app->group('/passkeys', function (RouteCollectorProxy $group): void {
		// Login ceremony (no auth required)
		$group->get('/login/options', Auth\PasskeyLoginOptionsAction::class);
		$group->post('/login', Auth\PasskeyLoginAction::class);

		// Registration & management (auth required)
		$group->group('', function (RouteCollectorProxy $auth): void {
			$auth->get('/register/options', Auth\PasskeyRegisterOptionsAction::class);
			$auth->post('/register', Auth\PasskeyRegisterAction::class);
			$auth->get('/list', Auth\PasskeyListAction::class);
			$auth->get('/list/html', Auth\PasskeyListHtmlAction::class);
			$auth->delete('/{credentialId}', Auth\PasskeyDeleteAction::class);
		})->add(AuthMiddleware::class)->add(PasskeyEditionMiddleware::class);
	})->add(NoCacheMiddleware::class);
};
