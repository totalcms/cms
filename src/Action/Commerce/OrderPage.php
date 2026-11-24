<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Download\DownloadLinks;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\StorefrontPage;
use TotalCMS\Domain\Commerce\Subscription\CustomerSubscriptions;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * The page a buyer sees for one order: after paying, and from the link in
 * their receipt. Shared by the return, cancel and status routes.
 *
 * Every one of those routes is opened by the order's signed link and by
 * nothing else. A missing or wrong link is a 404, the same as an order that
 * does not exist, so the routes cannot be used to find order ids.
 */
final readonly class OrderPage
{
	/** How many times the page asks again before it stops: 20 times, 3 seconds apart. */
	private const MAX_POLLS = 20;

	public function __construct(
		private OrderAccess $access,
		private TwigRenderer $twig,
		private StorefrontPage $pages,
		private CommerceSettings $settings,
		private Config $config,
		private DownloadLinks $downloads,
		private CustomerSubscriptions $subscriptions,
	) {
	}

	/** @throws HttpNotFoundException unless the request carries a live link to exactly this order */
	public function order(ServerRequestInterface $request, string $orderId): OrderData
	{
		return $this->access->verify($orderId, $this->token($request)) ?? throw new HttpNotFoundException($request);
	}

	/** The whole page: the store's own order page if it has set one, otherwise the built-in one. */
	public function page(ServerRequestInterface $request, ResponseInterface $response, OrderData $order): ResponseInterface
	{
		$response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
		$vars     = $this->vars($request, $order);
		$custom   = $this->pages->render($this->settings->returnPage(), $vars);
		if ($custom !== null) {
			$response->getBody()->write($custom);

			return $response;
		}

		return $this->twig->template($response, 'commerce/order-status.twig', $vars);
	}

	/** The part of the page that says where the payment stands, which reloads itself while that is unknown. */
	public function poll(ServerRequestInterface $request, ResponseInterface $response, OrderData $order): ResponseInterface
	{
		return $this->twig->template($response->withHeader('Content-Type', 'text/html; charset=utf-8'), 'commerce/order-status-poll.twig', $this->vars($request, $order));
	}

	/** @return array<string,mixed> */
	private function vars(ServerRequestInterface $request, OrderData $order): array
	{
		$asked = $request->getQueryParams()['n'] ?? 0;
		$polls = is_numeric($asked) ? max(0, (int)$asked) : 0;

		return [
			'order'   => $this->downloads->forBuyer($order),
			// For an order that is a payment on a subscription: where its buyer manages that.
			'subscriptionUrl' => $this->subscriptions->urlForOrder($order),
			'token'   => $this->token($request),
			'base'    => $this->config->api,
			'polls'   => $polls,
			'waiting' => $order->paymentStatus() === 'pending' && $polls < self::MAX_POLLS,
		];
	}

	/** The signed link the request carries, or '' for none. */
	public function token(ServerRequestInterface $request): string
	{
		$token = $request->getQueryParams()['t'] ?? '';

		return is_string($token) ? $token : '';
	}
}
