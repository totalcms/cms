<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Data\CheckoutStart;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\AdminEvent;
use TotalCMS\Domain\Commerce\Order\CheckoutInput;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderBuilder;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Order\OrderEvents;
use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckoutProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Starts a checkout: turns the buyer's cart into an order the server built,
 * and hands a payment provider the total the server worked out.
 *
 * Nothing here marks an order paid, with one exception: an order that comes
 * to nothing is paid on the spot, because there is no money to wait for.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") checkout is where the
 *                                                  cart, the catalog, orders
 *                                                  and providers meet
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") for the same reason
 */
final readonly class CheckoutService
{
	/** The provider recorded on an order that cost nothing. */
	public const FREE = 'free';

	private LoggerInterface $logger;

	public function __construct(
		private CartService $cart,
		private OrderBuilder $builder,
		private CheckoutValidatorRegistry $validators,
		private PaymentProviderRegistry $providers,
		private OrderRepository $orders,
		private OrderStateMachine $machine,
		private OrderAccess $access,
		private OrderEvents $events,
		private TransactionLedger $ledger,
		private PaymentEventProcessor $processor,
		private TaxCalculator $tax,
		private CommerceSettings $settings,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * @throws CommerceException with the code and status the route answers with
	 */
	public function start(CheckoutInput $input, PricingContext $ctx): CheckoutStart
	{
		if (filter_var($input->email, FILTER_VALIDATE_EMAIL) === false || !str_contains(substr((string)strrchr($input->email, '@'), 1), '.')) {
			throw CommerceException::emailInvalid();
		}

		// The code is checked again here, with the email: a code that stopped
		// being valid stops the checkout, before any order or payment exists.
		$draft = $this->builder->build($this->cart->items(), $input, $ctx, $this->cart->discountCode());
		$this->requireWhere($draft, $input);

		$free     = $draft->totals->grandTotal === 0 && !$draft->hasRecurringLine();
		$provider = $free ? null : $this->provider($input->provider, $draft);

		$refusal = $this->validators->refusal($draft, $ctx);
		if ($refusal !== null) {
			throw CommerceException::checkoutInvalid($refusal);
		}
		$this->cart->verifyStock();

		$cartKey = $this->cart->checkoutKey();
		$this->cancelStalePending($cartKey);
		$order = $this->orders->create($draft->withMetadata(['cartKey' => $cartKey] + $draft->metadata, []), $provider?->id() ?? self::FREE);

		$start = $provider instanceof PaymentProvider ? $this->toProvider($order, $draft, $provider) : $this->paidForNothing($order);
		$this->cart->markCheckout($order->id());

		return $start;
	}

	/**
	 * Give up on the orders this cart started that were never paid, so a
	 * buyer who goes back and changes the cart is not left with an order at
	 * the old total.
	 *
	 * @return int how many were cancelled
	 */
	public function cancelStalePending(string $cartKey): int
	{
		$cancelled = 0;
		foreach ($this->orders->pendingForCart($cartKey) as $stale) {
			$this->cancel($stale->id());
			$cancelled++;
		}

		return $cancelled;
	}

	/**
	 * An order that ships, or is taxed, has to say where: the country, and
	 * the region too where the store's tax depends on it.
	 *
	 * @throws CommerceException
	 */
	private function requireWhere(OrderDraft $draft, CheckoutInput $input): void
	{
		$taxed = $this->tax->mode() !== TaxCalculator::NONE;
		if (!$draft->ships() && !$taxed) {
			return;
		}

		$address = $input->shipping ?? $input->billing;
		if ($address === null || $address->country === '') {
			throw $draft->ships() ? CommerceException::addressRequired() : CommerceException::taxCountryRequired();
		}
		if ($taxed && $address->region === '' && $this->tax->needsRegion($address->country)) {
			throw CommerceException::regionRequired();
		}
	}

	/** @throws CommerceException when the provider cannot take this order */
	private function provider(string $id, OrderDraft $draft): PaymentProvider
	{
		foreach ($this->providers->forCart($draft->hasRecurringLine()) as $provider) {
			if ($provider->id() === $id && $provider->supportsCurrency($draft->currency)) {
				return $provider;
			}
		}

		throw CommerceException::checkoutUnavailable();
	}

	private function toProvider(OrderData $order, OrderDraft $draft, PaymentProvider $provider): CheckoutStart
	{
		$this->events->dispatch(CoreEvent::ORDER_CREATED, $order, $order->grandTotal());

		try {
			$session = $provider->createCheckout($this->request($order, $draft, $this->uiModeFor($provider)));
		} catch (ProviderException $e) {
			$this->cancel($order->id());
			// The provider's code, never its message: that can quote the request.
			$this->logger->error('A payment provider could not start a checkout; the order was cancelled', ['provider' => $provider->id(), 'orderId' => $order->id(), 'code' => $e->providerCode]);

			throw CommerceException::providerError();
		}

		// The client secret is kept so the pay page can be reloaded without a new session.
		$order = $this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with([
			'providerSessionRef'   => $session->providerSessionRef,
			'providerClientSecret' => (string)$session->clientSecret,
		]));
		$this->ledger->record($order, 'checkout_created', 'pending', $order->grandTotalMoney(), $session->providerSessionRef, '', 'system', []);

		$embed = $this->embedFor($order);

		return $embed instanceof EmbeddedCheckout
			? new CheckoutStart($order, $this->orderUrl('pay', $order), $embed)
			: new CheckoutStart($order, $session->redirectUrl);
	}

	/**
	 * Where a buyer pays with this provider: `embedded` (in the store's own
	 * page) only when the store asked for that and the provider can do it.
	 * Any other checkout is on the provider's page.
	 */
	public function uiModeFor(PaymentProvider $provider): string
	{
		return $this->settings->checkoutMode() === 'embedded' && $provider instanceof EmbeddedCheckoutProvider && $provider->capabilities()->embedded
			? 'embedded'
			: 'hosted';
	}

	/**
	 * What the store's page mounts to take payment for an order, from the
	 * session checkout already made. Null when there is nothing to mount: the
	 * order is not waiting to be paid, or it was not an on-page checkout.
	 */
	public function embedFor(OrderData $order): ?EmbeddedCheckout
	{
		$provider = $this->providers->get($order->provider());
		if ($order->paymentStatus() !== 'pending' || $order->providerClientSecret() === '' || !$provider instanceof EmbeddedCheckoutProvider) {
			return null;
		}

		return $provider->embed(new CheckoutSession($order->providerSessionRef(), '', $order->providerClientSecret()));
	}

	/** A signed link to one of the order's own pages: `return`, `cancel` or `pay`. */
	public function orderUrl(string $page, OrderData $order): string
	{
		return $this->url($page, $order);
	}

	/** Nothing to pay: the order is paid now, and the buyer goes straight to its page. */
	private function paidForNothing(OrderData $order): CheckoutStart
	{
		$this->events->dispatch(CoreEvent::ORDER_CREATED, $order, 0);
		$this->processor->process(
			new PaymentEvent(self::FREE, 'free-' . $order->id(), PaymentEventType::Paid, $order->id(), Money::zero($order->currency()), self::FREE),
			'system',
		);

		$order = $this->orders->find($order->id()) ?? $order;

		return new CheckoutStart($order, $this->url('return', $order));
	}

	private function request(OrderData $order, OrderDraft $draft, string $uiMode): CheckoutRequest
	{
		$zero     = Money::zero($draft->currency);
		$metadata = ['orderId' => $order->id(), 'orderNumber' => (string)$order->number()];
		foreach ($draft->metadata as $key => $value) {
			if (is_scalar($value) && !isset($metadata[$key])) {
				$metadata[$key] = (string)$value;
			}
		}

		return new CheckoutRequest(
			orderId: $order->id(),
			orderNumber: $order->number(),
			total: $order->grandTotalMoney(),
			lines: array_values(array_map(
				static fn (OrderLineDraft $line): CheckoutLineSummary => new CheckoutLineSummary($line->title, $line->qty, Money::of($line->unitPrice, $draft->currency), Money::of($line->discountTotal, $draft->currency), $zero),
				$draft->lines,
			)),
			shipping: Money::of($draft->totals->shippingTotal, $draft->currency),
			tax: Money::of($draft->totals->taxMode === TaxCalculator::EXCLUSIVE ? $draft->totals->taxTotal : 0, $draft->currency),
			email: $order->email(),
			successUrl: $this->url('return', $order),
			cancelUrl: $this->url('cancel', $order),
			// On-page checkout has no cancel address: the buyer is still on the store's page.
			returnUrl: $uiMode === 'embedded' ? $this->url('return', $order) : '',
			metadata: $metadata,
			idempotencyKey: 'checkout-' . $order->id(),
			uiMode: $uiMode,
		);
	}

	private function cancel(string $orderId): void
	{
		$this->orders->withLock($orderId, function (OrderData $order): OrderData {
			$transition = $this->machine->applyAdmin($order, AdminEvent::Cancel);

			// A cancelled order's session at the provider is of no more use to anyone.
			return $transition->applied
				? $order->withStatuses($transition->paymentStatus, $transition->fulfillmentStatus, $transition->status)->with(['providerClientSecret' => ''])
				: $order;
		});
	}

	/** A signed link to one of the order's own pages. */
	private function url(string $page, OrderData $order): string
	{
		return rtrim($this->config->url, '/') . rtrim($this->config->api, '/') . "/commerce/{$page}/{$order->id()}?t=" . rawurlencode($this->access->token($order));
	}
}
