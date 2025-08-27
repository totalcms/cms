<?php

use Slim\App;
use TotalCMS\Action\Auth;

return function (App $app): void {
	$app->any('/logout', Auth\AuthLogoutAction::class)->setName('logout');
	$app->any('/denied', Auth\AuthDeniedAction::class)->setName('denied');
	$app->get('/login[/{collection}]', Auth\AuthLoginAction::class)->setName('login');
	$app->post('/login[/{collection}]', Auth\AuthLoginSubmitAction::class);
};
