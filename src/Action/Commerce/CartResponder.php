<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Cart\CartLineView;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Cart\CartView;
use TotalCMS\Domain\Commerce\Discount\AppliedDiscount;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Pricing\PricingContextFactory;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * What every cart route has in common: it reads the request the same way,
 * and it answers the caller in the caller's own terms.
 *
 * - An HTMX page gets a cart partial: the widget, or the cart page when the
 *   request says `part=page`. After a change it also gets `HX-Trigger:
 *   cart-updated`, so every cart on the page reloads itself.
 * - A script gets JSON, with every amount a whole number of minor units.
 * - A plain form gets a redirect back to the page it was on.
 *
 * Nothing here reads a price from the request.
 */
final readonly class CartResponder
{
	/** The cart partials a request may ask for, and their templates. */
	private const PARTS = [
		'widget'   => 'commerce/cart-widget.twig',
		'page'     => 'commerce/cart-page.twig',
		'discount' => 'commerce/discount-form.twig',
		'drawer'   => 'commerce/cart-drawer-body.twig',
		'count'    => 'commerce/cart-count.twig',
	];

	/**
	 * "Swap nothing": the caller changes the cart and leaves showing it to
	 * the cart pieces on the page, which reload themselves on `cart-updated`.
	 */
	private const NONE = 'none';

	/** Parts that have nowhere to print a notice, so must not use one up. */
	private const SILENT = ['count'];

	/** Keys a cart request has no use for. Seeing one means a client is trying to set a price. */
	private const PRICE_KEYS = ['price', 'amount', 'total', 'unitPrice'];

	private const FLASH = 'commerce.cart';

	private LoggerInterface $logger;

	public function __construct(
		private CartService $cart,
		private PricingContextFactory $contexts,
		private JsonRenderer $json,
		private TwigRenderer $twig,
		private SessionInterface $session,
		private CSRFTokenManager $csrf,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * Make a change to the cart and answer with the cart as it is afterwards.
	 *
	 * @param \Closure(array<mixed>): void $change given the request body; throws CommerceException to refuse
	 */
	public function change(ServerRequestInterface $request, ResponseInterface $response, \Closure $change): ResponseInterface
	{
		// Asked for a part that does not exist: say so before changing anything.
		if ($this->part($request) === null) {
			return $this->show($request, $response);
		}

		$body = $request->getParsedBody();
		$body = is_array($body) ? $body : [];
		$this->notePriceKeys($body);

		try {
			$change($body);
		} catch (CommerceException $e) {
			return $this->refuse($request, $response, $e);
		}

		return $this->show($request, $response, changed: true);
	}

	/** Answer with the cart as it is. */
	public function show(ServerRequestInterface $request, ResponseInterface $response, bool $changed = false): ResponseInterface
	{
		$part = $this->part($request);
		if ($part === null) {
			return $this->error($response, 'part_invalid', 'Ask for the cart as "widget", "page", "drawer", "count", "discount" or "none".', 422);
		}
		if ($part === self::NONE) {
			return $changed ? $response->withHeader('HX-Trigger', 'cart-updated') : $response;
		}

		if ($part !== '') {
			$response = $this->html($response, $part, $this->view());

			return $changed ? $response->withHeader('HX-Trigger', 'cart-updated') : $response;
		}
		if ($changed && !$this->wantsJson($request)) {
			return $response->withStatus(303)->withHeader('Location', $this->back($request));
		}

		return $this->json->json($response, $this->data($this->view()));
	}

	/**
	 * A text field of the body, or '' when it is missing or not text.
	 *
	 * @param array<mixed> $body
	 */
	public function text(array $body, string $key): string
	{
		$value = $body[$key] ?? '';

		return is_string($value) || is_int($value) ? trim((string)$value) : '';
	}

	/**
	 * A quantity from the body: $default when none is sent, 0 (which is refused) when it is not a whole number.
	 *
	 * @param array<mixed> $body
	 */
	public function qty(array $body, int $default): int
	{
		$qty = $body['qty'] ?? $default;

		return is_int($qty) || (is_string($qty) && preg_match('/^-?\d{1,9}\z/', $qty) === 1) ? (int)$qty : 0;
	}

	private function refuse(ServerRequestInterface $request, ResponseInterface $response, CommerceException $e): ResponseInterface
	{
		$part = $this->part($request);

		// Nothing is swapped, so the reason is kept for the carts on the page,
		// which are told to reload. This announcement is safe on a refusal:
		// it answers a POST, never the reload it causes.
		if ($part === self::NONE) {
			$this->flash($e->getMessage());

			return $response->withHeader('X-Commerce-Error', $e->errorCode)->withHeader('HX-Trigger', 'cart-updated');
		}

		// A page shows the cart with the reason in it. The status stays 200 so
		// HTMX swaps it in; a script that needs the code reads the header.
		if ($part !== null && $part !== '') {
			return $this->html($response, $part, $this->view(), [$e->getMessage()])->withHeader('X-Commerce-Error', $e->errorCode);
		}
		if ($this->wantsJson($request)) {
			return $this->error($response, $e->errorCode, $e->getMessage(), $e->status);
		}

		// A plain form goes back to its page; the next cart shown says why.
		$this->session->getFlash()->add(self::FLASH, $e->getMessage());

		return $response->withStatus(303)->withHeader('Location', $this->back($request));
	}

	/** Something to tell the buyer the next time a cart is shown, once. */
	public function flash(string $message): void
	{
		$this->session->getFlash()->add(self::FLASH, $message);
	}

	/** A refusal for a script, in the shape every API error has, with a code to match on. */
	private function error(ResponseInterface $response, string $code, string $message, int $status): ResponseInterface
	{
		return $this->json->json($response, ['error' => ['code' => $code, 'message' => $message]], $status);
	}

	/** Who is buying, for a cart action that needs to price the cart. */
	public function context(): PricingContext
	{
		return $this->contexts->current();
	}

	private function view(): CartView
	{
		return $this->cart->view($this->contexts->current());
	}

	/**
	 * Which partial the caller wants: '' for none (JSON or a redirect), null
	 * for one that does not exist.
	 */
	private function part(ServerRequestInterface $request): ?string
	{
		$asked = $request->getQueryParams()['part'] ?? null;
		if ($asked === null) {
			return $request->getHeaderLine('HX-Request') === 'true' ? 'widget' : '';
		}

		return is_string($asked) && (isset(self::PARTS[$asked]) || $asked === self::NONE) ? $asked : null;
	}

	/** @param list<string> $notices more to say than the cart's own notices */
	private function html(ResponseInterface $response, string $part, CartView $cart, array $notices = []): ResponseInterface
	{
		// Reading a notice uses it up, so only a part that prints notices reads them.
		$flash = in_array($part, self::SILENT, true) ? [] : $this->session->getFlash()->get(self::FLASH);

		return $this->twig->template($response->withHeader('Content-Type', 'text/html; charset=utf-8'), self::PARTS[$part], [
			'cart'    => $cart,
			'notices' => [...$notices, ...array_values(array_filter($flash, is_string(...))), ...$cart->notices],
			'base'    => $this->config->api,
			'csrf'    => $this->csrf->getTokenField(),
		]);
	}

	/**
	 * The cart for a script. Every amount is a whole number of minor units of `currency`.
	 *
	 * @return array<string,mixed>
	 */
	private function data(CartView $cart): array
	{
		return [
			'count'    => $cart->count,
			'subtotal' => $cart->subtotal->amount(),
			// What the code takes off, worked out now. The cart itself keeps only the code.
			'discount' => $cart->discount instanceof AppliedDiscount ? ['code' => $cart->discount->code, 'type' => $cart->discount->type, 'amount' => $cart->discount->amount] : null,
			'total'    => $cart->total->amount(),
			'currency' => $cart->subtotal->currency(),
			'lines'    => array_map(static fn (CartLineView $line): array => [
				'lineKey'       => $line->lineKey,
				'productId'     => $line->productId,
				'variantId'     => $line->variantId,
				'title'         => $line->title,
				'variantTitle'  => $line->variantTitle,
				'image'         => $line->image,
				'qty'           => $line->qty,
				'unitPrice'     => $line->unitPrice->amount(),
				'lineTotal'     => $line->lineTotal->amount(),
				'discountTotal' => $line->discountTotal,
				'priceSource'   => $line->priceSource,
			], $cart->lines),
			'notices'  => $cart->notices,
		];
	}

	/** Whether the caller is a script that reads JSON, not a page. */
	public function wantsJson(ServerRequestInterface $request): bool
	{
		return $request->getMethod() === 'GET'
			|| str_contains($request->getHeaderLine('Accept'), 'application/json')
			|| str_contains($request->getHeaderLine('Content-Type'), 'application/json');
	}

	/**
	 * Where a plain form came from, if that is a page of this site. Anything
	 * else goes to the home page: a redirect must never leave the site.
	 */
	public function back(ServerRequestInterface $request): string
	{
		$referer = $request->getHeaderLine('Referer');
		$from    = parse_url($referer);
		$host    = is_array($from) && in_array($from['scheme'] ?? '', ['http', 'https'], true) ? strtolower($from['host'] ?? '') : '';

		// The same two hosts the CSRF check trusts: the site's own domain and
		// the host this request came to.
		$ours = array_filter([
			strtolower((string)parse_url('//' . $this->config->domain, PHP_URL_HOST)),
			strtolower($request->getUri()->getHost()),
		]);

		return $host !== '' && in_array($host, $ours, true) ? $referer : '/';
	}

	/**
	 * A cart request has no price to send. One that sends one is noted once
	 * for the session, so a probing client shows in the log without filling it.
	 *
	 * @param array<mixed> $body
	 */
	private function notePriceKeys(array $body): void
	{
		$sent = array_values(array_intersect(self::PRICE_KEYS, array_keys($body)));
		if ($sent === [] || $this->session->get(SessionKeys::COMMERCE_PRICE_NOTED) === true) {
			return;
		}

		$this->session->set(SessionKeys::COMMERCE_PRICE_NOTED, true);
		$this->logger->notice('A cart request sent a price, which is ignored', ['keys' => $sent]);
	}
}
