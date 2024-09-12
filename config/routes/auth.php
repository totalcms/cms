<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Auth;

return function (App $app) {
	$app->group('/auth', function (RouteCollectorProxy $group) {
		// User Authentication
		$group->any('/logout', Auth\AuthLogoutAction::class)->setName('logout');
		$group->post('[/{collection}]', Auth\AuthLoginAction::class)->setName('login');
	});
};
