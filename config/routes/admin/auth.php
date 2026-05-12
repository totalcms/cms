<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Auth;
use TotalCMS\Middleware\Response\NoCacheMiddleware;

return function (App $app): void {
	$app->group('/admin', function (RouteCollectorProxy $group): void {
		$group->any('/logout', Auth\AuthLogoutAction::class)->setName('logout');
		$group->any('/denied', Auth\AuthDeniedAction::class)->setName('denied');
		$group->get('/login[/{collection}]', Auth\AuthLoginAction::class)->setName('login');
		$group->post('/login[/{collection}]', Auth\AuthLoginSubmitAction::class);
		$group->post('/register[/{collection}]', Auth\AuthRegisterSubmitAction::class)->setName('register');
		$group->get('/forgot-password[/{collection}]', Auth\ForgotPasswordAction::class)->setName('forgot-password');
		$group->post('/forgot-password[/{collection}]', Auth\ForgotPasswordSubmitAction::class);
		$group->get('/reset-password/{token}', Auth\ResetPasswordAction::class)->setName('reset-password');
		$group->post('/reset-password/{token}', Auth\ResetPasswordSubmitAction::class);
	})->add(NoCacheMiddleware::class);
};
