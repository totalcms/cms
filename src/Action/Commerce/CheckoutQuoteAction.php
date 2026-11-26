<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Cart\CartStore;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\CheckoutInput;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Commerce\Service\CheckoutService;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

/**
 * POST /commerce/checkout/quote: what the cart would come to as an order,
 * with shipping and tax for the address on the checkout form.
 *
 * The form posts itself here whenever what the totals depend on changes,
 * and puts the answer where its totals are. A script that asks for JSON
 * gets the numbers. Nothing is made and no payment provider is asked.
 */
final readonly class CheckoutQuoteAction
{
	public function __construct(
		private CheckoutService $checkout,
		private CartStore $store,
		private CartResponder $responder,
		private PricingContextFactory $contexts,
		private CommerceSettings $settings,
		private JsonRenderer $json,
		private TwigRenderer $twig,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body               = $request->getParsedBody();
		$body               = is_array($body) ? $body : [];
		$sent               = $body['attributes'] ?? [];
		$body['attributes'] = (is_array($sent) ? $sent : []) + $this->store->attributes();

		try {
			$input = CheckoutInput::fromRequest($body, $this->settings->checkoutAttributes());
			$quote = $this->checkout->quote($input, $this->contexts->current($input->attributes));
		} catch (CommerceException $e) {
			if ($this->responder->wantsJson($request)) {
				return $this->json->json($response, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
			}
			$response->getBody()->write('<p class="commerce-cart-notice" role="alert">' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');

			return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('X-Commerce-Error', $e->errorCode);
		}

		if ($this->responder->wantsJson($request)) {
			return $this->json->json($response, $quote->toArray());
		}

		return $this->twig->template($response->withHeader('Content-Type', 'text/html; charset=utf-8'), 'commerce/checkout-totals.twig', ['quote' => $quote->toArray()]);
	}
}
