<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Data\CheckoutStart;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\Address;
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
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionDraft;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
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
		private SubscriptionRepository $subscriptions,
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

		$free     = $draft->totals->grandTotal === 0 && !$draft->hasRecurringLine();
		$provider = $free ? null : $this->provider($input->provider, $draft);

		// A provider that works the tax out finds out where the buyer is and
		// adds its own, so the store's is left off. For any other the store's
		// own tax stands, and it has to know where the buyer is.
		$providerTax = $provider instanceof PaymentProvider && $this->providers->worksOutTax($provider);
		if ($providerTax) {
			$draft = $draft->leftToProvider();
		}
		// An order that costs nothing has no tax to place.
		$this->requireWhere($draft, $input, !$free && !$providerTax);

		$refusal = $this->validators->refusal($draft, $ctx);
		if ($refusal !== null) {
			throw CommerceException::checkoutInvalid($refusal);
		}
		$this->cart->verifyStock();
		$this->requireAccount($draft);

		$cartKey = $this->cart->checkoutKey();
		$this->cancelStalePending($cartKey);
		$order = $this->orders->create($draft->withMetadata(['cartKey' => $cartKey] + $draft->metadata, []), $provider?->id() ?? self::FREE);

		// The part billed again every period waits, pending, for this order's payment.
		$subscription = null;
		if ($draft->subscription instanceof SubscriptionDraft) {
			$subscription = $this->subscriptions->createPending($draft->subscription, $order);
			// The order says which subscription it started, now that there is one.
			$order        = $this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['subscriptionId' => $subscription->id()]));
		}

		$start = $provider instanceof PaymentProvider ? $this->toProvider($order, $draft, $provider, $subscription) : $this->paidForNothing($order);
		$this->cart->markCheckout($order->id());

		return $start;
	}

	/**
	 * What the cart would come to as an order, for the buyer to see before
	 * they check out. Nothing is made and no provider is asked. An order
	 * that cannot ship yet is still worked out, without shipping, and says
	 * why it cannot.
	 *
	 * @throws CommerceException when there is nothing to work out: an empty cart, a product that can no longer be bought
	 */
	public function quote(CheckoutInput $input, PricingContext $ctx): CheckoutQuote
	{
		$draft    = $this->builder->build($this->cart->items(), $input, $ctx, $this->cart->discountCode(), true);
		$provider = null;
		foreach ($this->providers->forCart($draft->hasRecurringLine()) as $candidate) {
			if ($candidate->id() === $input->provider) {
				$provider = $candidate;
			}
		}

		$providerTax = $provider instanceof PaymentProvider && $this->providers->worksOutTax($provider);
		if ($providerTax) {
			$draft = $draft->leftToProvider();
		}

		$where      = $input->shipping ?? $input->billing;
		$country    = $where instanceof Address ? $where->country : '';
		$storeTaxes = !$providerTax && $this->tax->mode() !== TaxCalculator::NONE;
		// No rate for an address the buyer did give: the store does not ship there.
		$problem    = !$draft->totals->shippingKnown && $country !== '' ? CommerceException::noShipping()->getMessage() : '';

		return CheckoutQuote::of($draft, $storeTaxes, $country !== '', $problem);
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
			$this->cancelPendingSubscription($stale->subscriptionId());
			$cancelled++;
		}

		return $cancelled;
	}

	/**
	 * An order that ships, or that the store taxes, has to say where: the
	 * country, and the region too where the store's tax depends on it.
	 *
	 * @param bool $storeTaxes false when the payment provider works the tax out and asks the buyer itself
	 *
	 * @throws CommerceException
	 */
	private function requireWhere(OrderDraft $draft, CheckoutInput $input, bool $storeTaxes): void
	{
		$taxed = $storeTaxes && $this->tax->mode() !== TaxCalculator::NONE;
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

	/**
	 * The subscription an order was to start will never start: its order was
	 * given up on. Only one still waiting is closed; nobody is told, because
	 * nothing ever began.
	 */
	public function cancelPendingSubscription(string $subscriptionId): void
	{
		if ($subscriptionId === '' || !$this->subscriptions->exists($subscriptionId)) {
			return;
		}

		$this->subscriptions->withLock($subscriptionId, static fn (SubscriptionData $subscription): SubscriptionData => $subscription->status() === SubscriptionData::PENDING
			? $subscription->with(['status' => SubscriptionData::CANCELED, 'canceledAt' => date(DATE_ATOM)])
			: $subscription);
	}

	/**
	 * A line that gives its buyer an access group gives it to an account,
	 * so somebody has to be signed in to one.
	 *
	 * @throws CommerceException `account_required`
	 */
	private function requireAccount(OrderDraft $draft): void
	{
		if ($draft->customerId !== '') {
			return;
		}

		foreach ($draft->lines as $line) {
			if ($line->accessGroup !== '') {
				throw CommerceException::accountRequired();
			}
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

	private function toProvider(OrderData $order, OrderDraft $draft, PaymentProvider $provider, ?SubscriptionData $subscription = null): CheckoutStart
	{
		$this->events->dispatch(CoreEvent::ORDER_CREATED, $order, $order->grandTotal());

		try {
			$session = $provider->createCheckout($this->request($order, $draft, $this->uiModeFor($provider), $subscription));
		} catch (ProviderException $e) {
			$this->cancel($order->id());
			$this->cancelPendingSubscription($order->subscriptionId());
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

	private function request(OrderData $order, OrderDraft $draft, string $uiMode, ?SubscriptionData $subscription = null): CheckoutRequest
	{
		$again    = $draft->subscription;
		// The code is told to the provider by name, never as an amount: what
		// it takes off is already in the totals.
		$metadata = ['orderId' => $order->id(), 'orderNumber' => (string)$order->number()] + array_filter(['discountCode' => $order->discountCode()]);
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
				static fn (OrderLineDraft $line): CheckoutLineSummary => new CheckoutLineSummary(
					$line->title,
					$line->qty,
					Money::of($line->unitPrice, $draft->currency),
					Money::of($line->discountTotal, $draft->currency),
					// What the code takes off this line on every later payment.
					Money::of($again?->lines[$line->lineId]->discountTotal ?? 0, $draft->currency),
					$line->isRecurring() ? new Recurrence($line->interval, $line->intervalCount, $line->trialDays) : null,
					$line->taxable,
				),
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
			mode: $again instanceof SubscriptionDraft ? 'subscription' : 'payment',
			uiMode: $uiMode,
			recurringTotal: $again instanceof SubscriptionDraft ? Money::of($again->totals->grandTotal, $draft->currency) : null,
			recurringTax: $again instanceof SubscriptionDraft ? Money::of($again->totals->taxMode === TaxCalculator::EXCLUSIVE ? $again->totals->taxTotal : 0, $draft->currency) : null,
			subscriptionId: $subscription?->id() ?? '',
			providerCustomerRef: $subscription instanceof SubscriptionData ? $this->knownCustomer($subscription) : '',
			providerTax: $order->taxMode() === TaxCalculator::PROVIDER,
			shipTo: $order->taxMode() === TaxCalculator::PROVIDER && $draft->ships() ? $draft->shippingAddress : null,
			taxIncluded: $this->tax->mode() === TaxCalculator::INCLUSIVE,
		);
	}

	/**
	 * The buyer's customer at this provider, from a subscription they had
	 * before, so the provider keeps one customer for one person. '' for a
	 * guest, or a first subscription.
	 */
	private function knownCustomer(SubscriptionData $new): string
	{
		foreach ($this->subscriptions->forCustomer($new->customerCollection(), $new->customerId()) as $earlier) {
			if ($earlier->provider() === $new->provider() && $earlier->providerCustomerRef() !== '') {
				return $earlier->providerCustomerRef();
			}
		}

		return '';
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
