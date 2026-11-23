<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartStore;
use TotalCMS\Domain\Commerce\Data\CheckoutStart;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\CheckoutInput;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\CheckoutService;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

/**
 * POST /commerce/checkout: build the order and send the buyer to pay.
 *
 * The request says how the buyer pays, who they are and where it goes. It
 * says nothing about what anything costs: a price in the body is not read.
 *
 * Like the cart routes, it answers each caller in its own terms: a plain
 * form is redirected to the provider, an HTMX page is told to go there, a
 * script is given the address.
 */
final readonly class CheckoutStartAction
{
	public function __construct(
		private CheckoutService $checkout,
		private CartStore $store,
		private CartResponder $responder,
		private PricingContextFactory $contexts,
		private CommerceSettings $settings,
		private SessionInterface $session,
		private JsonRenderer $json,
		private TwigRenderer $twig,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body = $request->getParsedBody();
		$body = is_array($body) ? $body : [];
		// What the buyer typed on this form, over what they gave the cart earlier.
		$sent               = $body['attributes'] ?? [];
		$body['attributes'] = (is_array($sent) ? $sent : []) + $this->store->attributes();

		try {
			$input = CheckoutInput::fromRequest($body, $this->settings->checkoutAttributes());
			$start = $this->checkout->start($input, $this->contexts->current($input->attributes));
		} catch (CommerceException $e) {
			return $this->refuse($request, $response, $e);
		}

		// Where to bring the buyer back to if they give up at the provider.
		$this->session->set(SessionKeys::COMMERCE_CHECKOUT_FROM, $this->responder->back($request));

		if ($start->embed instanceof EmbeddedCheckout) {
			return $this->onPage($request, $response, $start, $start->embed);
		}

		if ($this->isHtmx($request)) {
			return $response->withHeader('HX-Redirect', $start->redirectUrl);
		}
		if ($this->responder->wantsJson($request)) {
			return $this->json->json($response, ['redirectUrl' => $start->redirectUrl, 'orderId' => $start->order->id()]);
		}

		return $response->withStatus(303)->withHeader('Location', $start->redirectUrl);
	}

	/**
	 * The buyer pays on the store's own page. An HTMX page gets the payment
	 * form in place of the checkout form; a script gets what it needs to
	 * mount the form itself; a plain form is sent to the pay page.
	 */
	private function onPage(ServerRequestInterface $request, ResponseInterface $response, CheckoutStart $start, EmbeddedCheckout $embed): ResponseInterface
	{
		$mount = ['moduleUrl' => $embed->moduleUrl, 'config' => $embed->config];

		if ($this->isHtmx($request)) {
			return $this->twig->template($response->withHeader('Content-Type', 'text/html; charset=utf-8'), 'commerce/checkout-embedded.twig', ['embed' => $mount])
				->withHeader('HX-Retarget', '#commerce-checkout-form')
				->withHeader('HX-Reswap', 'outerHTML');
		}
		if ($this->responder->wantsJson($request)) {
			return $this->json->json($response, ['embed' => $mount, 'payUrl' => $start->redirectUrl, 'orderId' => $start->order->id()]);
		}

		return $response->withStatus(303)->withHeader('Location', $start->redirectUrl);
	}

	private function refuse(ServerRequestInterface $request, ResponseInterface $response, CommerceException $e): ResponseInterface
	{
		// HTMX swaps the reason into the form. The status stays 200 so every
		// version swaps it; a script that needs the code reads the header.
		if ($this->isHtmx($request)) {
			$response->getBody()->write('<p class="commerce-cart-notice" role="alert">' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');

			return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('X-Commerce-Error', $e->errorCode);
		}
		if ($this->responder->wantsJson($request)) {
			return $this->json->json($response, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
		}

		// A plain form goes back to its page; the next cart shown says why.
		$this->responder->flash($e->getMessage());

		return $response->withStatus(303)->withHeader('Location', $this->responder->back($request));
	}

	private function isHtmx(ServerRequestInterface $request): bool
	{
		return $request->getHeaderLine('HX-Request') === 'true';
	}
}
