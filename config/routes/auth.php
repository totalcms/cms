<?php

use Slim\App;
use TotalCMS\Action\Auth;

return function (App $app): void {
	$app->any('/logout', Auth\AuthLogoutAction::class)->setName('logout');
	$app->any('/denied', Auth\AuthDeniedAction::class)->setName('denied');
	$app->get('/login[/{collection}]', Auth\AuthLoginAction::class)->setName('login');
	$app->post('/login[/{collection}]', Auth\AuthLoginSubmitAction::class);
	$app->get('/forgot-password[/{collection}]', Auth\ForgotPasswordAction::class)->setName('forgot-password');
	$app->post('/forgot-password[/{collection}]', Auth\ForgotPasswordSubmitAction::class);
	$app->get('/reset-password/{token}', Auth\ResetPasswordAction::class)->setName('reset-password');
	$app->post('/reset-password/{token}', Auth\ResetPasswordSubmitAction::class);
};
