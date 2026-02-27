<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Auth;
use TotalCMS\Middleware\Auth\AuthMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

return function (App $app): void {
	$app->group('/passkeys', function (RouteCollectorProxy $group): void {
		// Login ceremony (no auth required)
		$group->get('/login/options', Auth\PasskeyLoginOptionsAction::class);
		$group->post('/login', Auth\PasskeyLoginAction::class);

		// Registration & management (auth required)
		$group->group('', function (RouteCollectorProxy $auth): void {
			$auth->get('/register/options', Auth\PasskeyRegisterOptionsAction::class);
			$auth->post('/register', Auth\PasskeyRegisterAction::class);
			$auth->get('/list', Auth\PasskeyListAction::class);
			$auth->delete('/{credentialId}', Auth\PasskeyDeleteAction::class);
		})->add(AuthMiddleware::class);
	})->add(NoCacheMiddleware::class);
};
