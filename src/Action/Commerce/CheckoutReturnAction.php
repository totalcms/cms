<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * GET /commerce/return/{orderId}?t=…: where the provider sends the buyer
 * after they pay.
 *
 * Arriving here proves nothing: anyone with the link can open it. So it
 * never marks an order paid. It shows what the order's payment status is
 * now, and keeps asking while the provider's own word has not come.
 */
final readonly class CheckoutReturnAction
{
	private const CLEARED = 'cartCleared';

	private LoggerInterface $logger;

	public function __construct(
		private OrderPage $page,
		private PaymentProviderRegistry $providers,
		private CartService $cart,
		private OrderRepository $orders,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$order = $this->page->order($request, $args['orderId'] ?? '');

		try {
			// Some providers finish the payment here. Whatever they do, the
			// order changes only when their webhook says so.
			$this->providers->get($order->provider())?->completeReturn($order->view(), $request);
		} catch (ProviderException $e) {
			$this->logger->warning('A payment provider could not complete a return', ['provider' => $order->provider(), 'orderId' => $order->id(), 'code' => $e->providerCode]);
		}

		$this->clearCart($order);

		return $this->page->page($request, $response, $order);
	}

	/**
	 * Empty the cart this order came from, the first time its buyer comes
	 * back. Only that cart: the link also opens on another device, where the
	 * visitor's cart is a different one and is theirs to keep.
	 */
	private function clearCart(OrderData $order): void
	{
		if (($order->metadata()[self::CLEARED] ?? false) === true || $order->cartKey() === '' || $this->cart->cartKey() !== $order->cartKey()) {
			return;
		}

		$this->cart->clear();
		$this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['metadata' => [self::CLEARED => true] + $fresh->metadata()]));
	}
}
