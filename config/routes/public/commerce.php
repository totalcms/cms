<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Commerce\CartAddAction;
use TotalCMS\Action\Commerce\CartAttributesAction;
use TotalCMS\Action\Commerce\CartClearAction;
use TotalCMS\Action\Commerce\CartRemoveAction;
use TotalCMS\Action\Commerce\CartUpdateAction;
use TotalCMS\Action\Commerce\CartViewAction;
use TotalCMS\Middleware\Commerce\CartRateLimitMiddleware;
use TotalCMS\Middleware\Commerce\CommerceEditionMiddleware;
use TotalCMS\Middleware\Response\NoCacheMiddleware;
use TotalCMS\Middleware\Security\CSRFProtectionMiddleware;

/*
 * The storefront: the routes a shop's own pages call. Each one is refused
 * unless the store is switched on, is held to a per-address limit, and
 * (for a POST) must come from the site itself.
 *
 * Middleware runs from the last added to the first: the limit, then the
 * store check, then CSRF.
 */
return function (App $app): void {
	$app->group('/commerce/cart', function (RouteCollectorProxy $group): void {
		$group->get('', CartViewAction::class)->setName('commerce-cart');
		$group->post('/add', CartAddAction::class)->setName('commerce-cart-add');
		$group->post('/update', CartUpdateAction::class)->setName('commerce-cart-update');
		$group->post('/remove', CartRemoveAction::class)->setName('commerce-cart-remove');
		$group->post('/clear', CartClearAction::class)->setName('commerce-cart-clear');
		$group->post('/attributes', CartAttributesAction::class)->setName('commerce-cart-attributes');
	})->add(NoCacheMiddleware::class)
		->add(CSRFProtectionMiddleware::class)
		->add(CommerceEditionMiddleware::class)
		->add(CartRateLimitMiddleware::class);
};
