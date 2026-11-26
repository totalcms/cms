<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;
use TotalCMS\Domain\Commerce\Service\CheckoutService;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\StorefrontPage;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * GET /commerce/pay/{orderId}?t=…: the page a buyer pays on, when the
 * store takes payment on its own page.
 *
 * The provider's form is mounted from the session that checkout already
 * made and the order remembers, so reloading the page asks the provider for
 * nothing new. An order that is no longer waiting to be paid has no form to
 * show: the buyer is sent on to the order's page.
 */
final readonly class CheckoutPayAction
{
	public function __construct(
		private OrderPage $page,
		private CheckoutService $checkout,
		private StorefrontPage $pages,
		private CommerceSettings $settings,
		private TwigRenderer $twig,
		private Config $config,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$order = $this->page->order($request, $args['orderId'] ?? '');
		$embed = $this->checkout->embedFor($order);
		if (!$embed instanceof EmbeddedCheckout) {
			return $response->withStatus(303)->withHeader('Location', $this->checkout->orderUrl('return', $order));
		}

		$vars = [
			'order'     => $order->forBuyer(),
			'embed'     => ['moduleUrl' => $embed->moduleUrl, 'config' => $embed->config],
			'token'     => $this->page->token($request),
			'cancelUrl' => $this->checkout->orderUrl('cancel', $order),
			'base'      => $this->config->api,
		];

		$response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
		$custom   = $this->pages->render($this->settings->payPage(), $vars);
		if ($custom !== null) {
			$response->getBody()->write($custom);

			return $response;
		}

		return $this->twig->template($response, 'commerce/pay.twig', $vars);
	}
}
