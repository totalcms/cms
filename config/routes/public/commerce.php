<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use TotalCMS\Action\Commerce\CheckoutCancelAction;
use TotalCMS\Action\Commerce\CheckoutPayAction;
use TotalCMS\Action\Commerce\CheckoutReturnAction;
use TotalCMS\Action\Commerce\CheckoutStartAction;
use TotalCMS\Action\Commerce\OrderStatusAction;
use TotalCMS\Action\Commerce\CommerceDownloadAction;
use TotalCMS\Action\Commerce\CommerceWebhookAction;
use TotalCMS\Action\Commerce\CartAddAction;
use TotalCMS\Action\Commerce\CartAttributesAction;
use TotalCMS\Action\Commerce\CartClearAction;
use TotalCMS\Action\Commerce\CartDiscountAction;
use TotalCMS\Action\Commerce\CartDiscountRemoveAction;
use TotalCMS\Action\Commerce\CartRemoveAction;
use TotalCMS\Action\Commerce\CartUpdateAction;
use TotalCMS\Action\Commerce\CartViewAction;
use TotalCMS\Middleware\Commerce\CartRateLimitMiddleware;
use TotalCMS\Middleware\Commerce\CheckoutRateLimitMiddleware;
use TotalCMS\Middleware\Commerce\CommerceEditionMiddleware;
use TotalCMS\Middleware\Commerce\DiscountRateLimitMiddleware;
use TotalCMS\Middleware\Commerce\WebhookRateLimitMiddleware;
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

	// Trying a code is the one place a visitor can guess codes: its own, tight limit.
	$app->post('/commerce/cart/discount', CartDiscountAction::class)->setName('commerce-cart-discount')
		->add(NoCacheMiddleware::class)
		->add(CSRFProtectionMiddleware::class)
		->add(CommerceEditionMiddleware::class)
		->add(DiscountRateLimitMiddleware::class);
	$app->post('/commerce/cart/discount/remove', CartDiscountRemoveAction::class)->setName('commerce-cart-discount-remove')
		->add(NoCacheMiddleware::class)
		->add(CSRFProtectionMiddleware::class)
		->add(CommerceEditionMiddleware::class)
		->add(CartRateLimitMiddleware::class);

	// Starting a checkout makes an order and calls the payment provider, so
	// it has a much tighter limit of its own.
	$app->post('/commerce/checkout', CheckoutStartAction::class)->setName('commerce-checkout')
		->add(NoCacheMiddleware::class)
		->add(CSRFProtectionMiddleware::class)
		->add(CommerceEditionMiddleware::class)
		->add(CheckoutRateLimitMiddleware::class);

	// The buyer's own pages for one order. Each is opened by the order's
	// signed link (`?t=`) and by nothing else.
	$app->group('/commerce', function (RouteCollectorProxy $group): void {
		$group->get('/return/{orderId}', CheckoutReturnAction::class)->setName('commerce-return');
		$group->get('/cancel/{orderId}', CheckoutCancelAction::class)->setName('commerce-cancel');
		$group->get('/pay/{orderId}', CheckoutPayAction::class)->setName('commerce-pay');
		$group->get('/order/{orderId}/status', OrderStatusAction::class)->setName('commerce-order-status');
	})->add(NoCacheMiddleware::class)
		->add(CommerceEditionMiddleware::class)
		->add(CartRateLimitMiddleware::class);

	// A buyer's file. The token in the address is their right to it, so
	// there is no session and no gate: goods already paid for stay
	// downloadable on a store whose add-on has lapsed.
	$app->get('/commerce/download/{token}', CommerceDownloadAction::class)->setName('commerce-download')
		->add(NoCacheMiddleware::class)
		->add(CartRateLimitMiddleware::class);

	// Where a payment provider reports to the store. No session, no CSRF
	// token and no gate: what it has is a signature, and a store whose
	// add-on has lapsed must still hear about money it already took.
	$app->post('/commerce/webhook/{provider}', CommerceWebhookAction::class)->setName('commerce-webhook')
		->add(NoCacheMiddleware::class)
		->add(WebhookRateLimitMiddleware::class);
};
