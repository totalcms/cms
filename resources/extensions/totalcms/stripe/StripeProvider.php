<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Stripe;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckoutProvider;
use TotalCMS\Domain\Commerce\Payment\OrderView;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderCapabilities;
use TotalCMS\Domain\Commerce\Payment\ProviderEvent;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Payment\RefundResult;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Webhook\RawBody;
use TotalCMS\Domain\Webhook\WebhookVerifier;

/**
 * Stripe Checkout as a way to pay: the buyer pays on Stripe's own page, or
 * in Stripe's form mounted in the store's page, and Stripe's signed webhook
 * tells the store how it went.
 *
 * The store hands this one number, the order's total, and this makes sure
 * Stripe's page comes to exactly that before it asks for one.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") one provider, one class:
 *                                                    the branches are Stripe's
 *                                                    event types
 */
final readonly class StripeProvider implements EmbeddedCheckoutProvider
{
	public const ID = 'stripe';

	/** Stripe's own script, for the API version {@see StripeClient::VERSION} pins. Only ever loaded from js.stripe.com. */
	public const JS_URL = 'https://js.stripe.com/endive/stripe.js';

	/** Stripe's limits on metadata: this many keys, and this long a key and a value. */
	private const METADATA_KEYS  = 50;
	private const METADATA_KEY   = 40;
	private const METADATA_VALUE = 500;

	/** Stripe's tax code for something that is never taxed. */
	private const NOT_TAXABLE_CODE = 'txcd_00000000';

	/** Stripe's tax code for a delivery charge, which places tax by rules of their own. */
	private const SHIPPING_TAX_CODE = 'txcd_92010001';

	/** The reasons Stripe takes for a refund. Any other is sent as the buyer's request, with the words in metadata. */
	private const REFUND_REASONS = ['duplicate', 'fraudulent', 'requested_by_customer'];

	/**
	 * @param string                    $publishableKey the key a page may hold; needed only to take payment on the store's own page
	 * @param (\Closure(): string)|null $moduleUrl      where the page imports this extension's embed.js from; asked when a checkout needs it
	 *
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList") the extension's settings, one argument each
	 */
	public function __construct(
		private StripeClient $client,
		private string $secretKey,
		private string $webhookSecret,
		private string $statementDescriptor,
		private LoggerInterface $logger,
		private string $publishableKey = '',
		private ?\Closure $moduleUrl = null,
	) {
	}

	public function id(): string
	{
		return self::ID;
	}

	public function label(): string
	{
		return 'Stripe';
	}

	/**
	 * Stripe's form can be shown on the store's own page only with a
	 * publishable key. Without one this says so, and a store set to take
	 * payment on its own page sends the buyer to Stripe's page instead.
	 */
	public function capabilities(): ProviderCapabilities
	{
		return new ProviderCapabilities(embedded: preg_match('/^pk_(test|live)_[A-Za-z0-9]+\z/', $this->publishableKey) === 1, tax: true);
	}

	/** Ready when the secret key and the webhook secret are filled in. */
	public function isConfigured(): bool
	{
		return preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+\z/', $this->secretKey) === 1 && str_starts_with($this->webhookSecret, 'whsec_');
	}

	/** Which of Stripe's two modes the secret key in use is for: `test`, `live`, or '' with no usable key. */
	public function keyMode(): string
	{
		return preg_match('/^(?:sk|rk)_(test|live)_[A-Za-z0-9]+\z/', $this->secretKey, $found) === 1 ? $found[1] : '';
	}

	public function supportsCurrency(string $currency): bool
	{
		return preg_match('/^[A-Za-z]{3}\z/', $currency) === 1;
	}

	public function createCheckout(CheckoutRequest $request): CheckoutSession
	{
		if ($request->mode !== 'payment' || !in_array($request->uiMode, ['hosted', 'embedded'], true)) {
			throw new ProviderException('Stripe is not set up for that kind of checkout yet.', 'unsupported');
		}

		$sum = array_sum(array_map(static fn (array $item): int => $item['price_data']['unit_amount'] * $item['quantity'], $this->items($request, false)));
		if ($sum !== $request->total->amount()) {
			// The buyer must never be asked for anything but the order's total.
			throw new ProviderException("The items for order {$request->orderNumber} come to {$sum}, not the order's total of {$request->total->amount()}.", 'total_mismatch');
		}

		if ($request->providerTax) {
			try {
				return $this->open($request, true);
			} catch (ProviderException $e) {
				// Stripe out of reach is not Stripe saying no to tax.
				if ($e->providerCode === 'network') {
					throw $e;
				}
				// Stripe Tax is not set up, or the account lacks something it
				// needs. The buyer must not be the one who finds out: the sale
				// goes ahead without tax, and the owner is told.
				$this->logger->warning('Stripe would not start a checkout with its tax switched on, so the order was started without tax. Run `tcms stripe:tax` to see what Stripe Tax is missing', ['orderId' => $request->orderId, 'code' => $e->providerCode]);
			}

			// A key of its own: Stripe answers a key it has seen with its first answer.
			return $this->open($request, false, $request->idempotencyKey . '-notax');
		}

		return $this->open($request, false);
	}

	/**
	 * Ask Stripe for the checkout.
	 *
	 * @param bool $taxed have Stripe work the tax out and add it (Stripe Tax)
	 */
	private function open(CheckoutRequest $request, bool $taxed, ?string $idempotencyKey = null): CheckoutSession
	{
		$onPage = $request->uiMode === 'embedded';

		$intent = ['metadata' => ['orderId' => $request->orderId, 'orderNumber' => (string)$request->orderNumber], 'transfer_group' => 'order-' . $request->orderNumber];
		if ($this->statementDescriptor !== '') {
			$intent['statement_descriptor_suffix'] = $this->statementDescriptor;
		}

		// Stripe's own page is told where to send the buyer after paying and
		// after giving up. On the store's page there is one way back, and
		// Stripe refuses the other two.
		$where = $onPage
			? ['ui_mode' => 'embedded_page', 'return_url' => $request->returnUrl]
			: ['ui_mode' => 'hosted_page', 'success_url' => $request->successUrl, 'cancel_url' => $request->cancelUrl];

		$session = $this->client->post('/checkout/sessions', [
			'mode'                => 'payment',
			'client_reference_id' => $request->orderId,
			'metadata'            => $this->metadata($request),
			'payment_intent_data' => $intent,
			'line_items'          => $this->items($request, $taxed),
		] + $this->buyer($request, $taxed) + ($taxed ? ['automatic_tax' => ['enabled' => true]] : []) + $where, $idempotencyKey ?? $request->idempotencyKey);

		$id = $this->text($session, 'id');
		if ($onPage) {
			$secret = $this->text($session, 'client_secret');
			if ($id === '' || $secret === '') {
				throw new ProviderException('Stripe answered without a checkout to mount.', 'invalid_response');
			}

			return new CheckoutSession($id, '', $secret);
		}

		$url = $this->text($session, 'url');
		if ($id === '' || $url === '') {
			throw new ProviderException('Stripe answered without a checkout page.', 'invalid_response');
		}

		return new CheckoutSession($id, $url);
	}

	/**
	 * Who is paying, as the session names them: by email, or, when Stripe
	 * is to tax an order that ships, as a customer who has the address it
	 * ships to. Stripe taxes a customer by that address, and without one it
	 * would tax by the billing address it asks for itself.
	 *
	 * @return array<string,mixed>
	 */
	private function buyer(CheckoutRequest $request, bool $taxed): array
	{
		$shipTo = $taxed ? $request->shipTo : null;
		if (!$shipTo instanceof Address || $shipTo->country === '') {
			return ['customer_email' => $request->email];
		}

		$customer = $this->client->post('/customers', array_filter(['email' => $request->email, 'name' => $shipTo->name]) + [
			'shipping' => [
				'name'    => $shipTo->name !== '' ? $shipTo->name : $request->email,
				'address' => array_filter([
					'line1'       => $shipTo->line1,
					'line2'       => $shipTo->line2,
					'city'        => $shipTo->city,
					'state'       => $shipTo->region,
					'postal_code' => $shipTo->postalCode,
					'country'     => $shipTo->country,
				], static fn (string $part): bool => $part !== ''),
			],
			'metadata' => ['orderId' => $request->orderId],
		], $request->idempotencyKey . '-customer');

		$id = $this->text($customer, 'id');
		if ($id === '') {
			throw new ProviderException('Stripe answered without a customer.', 'invalid_response');
		}

		// A billing address Stripe asks for is kept on the customer, which
		// Stripe requires before it works out tax for one.
		return ['customer' => $id, 'customer_update' => ['address' => 'auto']];
	}

	/**
	 * What the store's page mounts Stripe's form with. The publishable key
	 * is public by design and the client secret is this one checkout's;
	 * the secret key and the webhook secret never leave the server.
	 */
	public function embed(CheckoutSession $session): EmbeddedCheckout
	{
		return new EmbeddedCheckout($this->moduleUrl !== null ? ($this->moduleUrl)() : '', [
			'publishableKey' => $this->publishableKey,
			'clientSecret'   => (string)$session->clientSecret,
			'scriptUrl'      => self::JS_URL,
		]);
	}

	/** Stripe needs nothing done when the buyer comes back: its webhook says how the payment went. */
	public function completeReturn(OrderView $order, ServerRequestInterface $request): void
	{
	}

	public function webhookVerifier(): WebhookVerifier
	{
		return new StripeWebhookVerifier($this->webhookSecret);
	}

	public function parseWebhook(ServerRequestInterface $request): ?ProviderEvent
	{
		$event  = json_decode(RawBody::of($request), true);
		$id     = is_array($event) ? ($event['id'] ?? null) : null;
		$type   = is_array($event) ? ($event['type'] ?? null) : null;
		$object = is_array($event) && is_array($event['data'] ?? null) ? ($event['data']['object'] ?? null) : null;
		if (!is_string($id) || $id === '' || !is_string($type) || !is_array($object)) {
			return null;
		}

		$previous = is_array($event['data']['previous_attributes'] ?? null) ? $event['data']['previous_attributes'] : [];

		return match ($type) {
			// Completed and paid: a card. Completed and not yet paid: a bank
			// payment, whose own event says later how it went.
			'checkout.session.completed'               => $this->text($object, 'payment_status') === 'paid' ? $this->session($id, PaymentEventType::Paid, $object) : null,
			'checkout.session.async_payment_succeeded' => $this->session($id, PaymentEventType::Paid, $object),
			'checkout.session.async_payment_failed'    => $this->session($id, PaymentEventType::Failed, $object),
			'checkout.session.expired'                 => $this->session($id, PaymentEventType::Expired, $object),
			'charge.refunded'                          => $this->refunded($id, $object, $previous),
			'charge.dispute.created'                   => $this->dispute($id, PaymentEventType::Disputed, $object),
			'charge.dispute.closed'                    => match ($this->text($object, 'status')) {
				'won'   => $this->dispute($id, PaymentEventType::DisputeWon, $object),
				'lost'  => $this->dispute($id, PaymentEventType::DisputeLost, $object),
				default => null,
			},
			default => null,
		};
	}

	public function fetchPayment(string $providerSessionRef): ?PaymentEvent
	{
		if ($providerSessionRef === '') {
			return null;
		}

		try {
			$session = $this->client->get('/checkout/sessions/' . rawurlencode($providerSessionRef));
		} catch (ProviderException) {
			return null;
		}

		// Not one of Stripe's event ids: a name of its own for "what the session says now".
		return match (true) {
			$this->text($session, 'payment_status') === 'paid' => $this->session("session-{$providerSessionRef}-paid", PaymentEventType::Paid, $session),
			$this->text($session, 'status') === 'expired'      => $this->session("session-{$providerSessionRef}-expired", PaymentEventType::Expired, $session),
			default                                            => null,
		};
	}

	/**
	 * Whether Stripe Tax is set up on the account these keys belong to.
	 * Asked by `tcms stripe:tax`; never during a checkout.
	 *
	 * @return array{ready: bool, missing: list<string>, registrations: int, error: string} `missing` is Stripe's own names
	 *                                                                                      for what its tax settings lack; `registrations` how many places tax is collected for (none means Stripe works
	 *                                                                                      out no tax anywhere); `error` a code when Stripe could not be asked
	 */
	public function taxStatus(): array
	{
		try {
			$settings      = $this->client->get('/tax/settings');
			$registrations = $this->client->get('/tax/registrations', ['status' => 'active', 'limit' => 100]);
		} catch (ProviderException $e) {
			return ['ready' => false, 'missing' => [], 'registrations' => 0, 'error' => $e->providerCode];
		}

		$details = is_array($settings['status_details'] ?? null) && is_array($settings['status_details']['pending'] ?? null) ? $settings['status_details']['pending'] : [];
		$missing = is_array($details['missing_fields'] ?? null) ? array_values(array_filter($details['missing_fields'], is_string(...))) : [];

		return [
			'ready'         => $this->text($settings, 'status') === 'active',
			'missing'       => $missing,
			'registrations' => is_array($registrations['data'] ?? null) ? count($registrations['data']) : 0,
			'error'         => '',
		];
	}

	public function refund(RefundRequest $request): RefundResult
	{
		$known = in_array($request->reason, self::REFUND_REASONS, true);

		try {
			$refund = $this->client->post('/refunds', [
				'payment_intent' => $request->providerPaymentRef,
				'amount'         => $request->amount->amount(),
				'reason'         => $known ? $request->reason : 'requested_by_customer',
				'metadata'       => array_filter(['orderId' => $request->orderId, 'reason' => $known ? '' : mb_substr($request->reason, 0, self::METADATA_VALUE)]),
			], $request->idempotencyKey);
		} catch (ProviderException $e) {
			return new RefundResult(false, '', $e->providerCode);
		}

		return new RefundResult(true, $this->text($refund, 'id'));
	}

	/**
	 * The items on Stripe's page. A line without a discount keeps its
	 * quantity. A discounted line is one item at what is charged for all of
	 * it, because a discount does not always divide evenly by the quantity.
	 * Shipping and tax are items of their own.
	 *
	 * When Stripe works the tax out, each item says how its price stands to
	 * that tax, and shipping says that it is shipping. A product the store
	 * does not tax says so. Any other names no tax code: Stripe uses the
	 * account's own.
	 *
	 * @return list<array{quantity: int, price_data: array{currency: string, unit_amount: int, product_data: array<string,string>, tax_behavior?: string}}>
	 */
	private function items(CheckoutRequest $request, bool $taxed): array
	{
		$currency = strtolower($request->total->currency());
		// The tax goes on top of the store's prices, or the prices already hold it: the store says which.
		$behavior = $taxed ? ['tax_behavior' => $request->taxIncluded ? 'inclusive' : 'exclusive'] : [];
		$item     = static fn (string $name, int $quantity, int $unitAmount, string $taxCode = ''): array => [
			'quantity'   => $quantity,
			'price_data' => ['currency' => $currency, 'unit_amount' => $unitAmount, 'product_data' => ['name' => $name] + ($taxCode !== '' ? ['tax_code' => $taxCode] : [])] + $behavior,
		];

		$code  = static fn (CheckoutLineSummary $line): string => $taxed && !$line->taxable ? self::NOT_TAXABLE_CODE : '';
		$items = array_map(
			static fn (CheckoutLineSummary $line): array => $line->discount->isZero()
				? $item($line->title, $line->qty, $line->unitAmount->amount(), $code($line))
				: $item("{$line->qty} × {$line->title}", 1, $line->unitAmount->multiply($line->qty)->subtract($line->discount)->amount(), $code($line)),
			$request->lines,
		);
		if (!$request->shipping->isZero()) {
			$items[] = $item('Shipping', 1, $request->shipping->amount(), $taxed ? self::SHIPPING_TAX_CODE : '');
		}
		if (!$request->tax->isZero()) {
			$items[] = $item('Tax', 1, $request->tax->amount());
		}

		return $items;
	}

	/**
	 * The order's metadata, cut to what Stripe takes.
	 *
	 * @return array<string,string>
	 */
	private function metadata(CheckoutRequest $request): array
	{
		$kept = [];
		$cut  = false;
		foreach (['orderId' => $request->orderId, 'orderNumber' => (string)$request->orderNumber] + $request->metadata as $key => $value) {
			$key = (string)$key;
			if (count($kept) >= self::METADATA_KEYS || strlen($key) > self::METADATA_KEY) {
				$cut = true;

				continue;
			}
			$cut        = $cut || strlen($value) > self::METADATA_VALUE;
			$kept[$key] = substr($value, 0, self::METADATA_VALUE);
		}

		if ($cut) {
			$this->logger->warning('An order has more metadata than Stripe takes; some was left out or shortened', ['orderId' => $request->orderId]);
		}

		return $kept;
	}

	/** @param array<array-key,mixed> $session a Checkout Session */
	private function session(string $eventId, PaymentEventType $type, array $session): PaymentEvent
	{
		$metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
		$orderId  = $this->text($session, 'client_reference_id');

		// A session Stripe taxed says how much, and what the items came to
		// without it: the total the store asked for.
		$taxed   = is_array($session['automatic_tax'] ?? null) && ($session['automatic_tax']['enabled'] ?? false) === true;
		$details = is_array($session['total_details'] ?? null) ? $session['total_details'] : [];

		return new PaymentEvent(
			provider: self::ID,
			eventId: $eventId,
			type: $type,
			orderId: $orderId !== '' ? $orderId : $this->text($metadata, 'orderId'),
			amount: $this->money($session, 'amount_total'),
			providerRef: $this->text($session, 'id'),
			providerPaymentRef: $this->text($session, 'payment_intent'),
			summary: array_filter(['paymentStatus' => $this->text($session, 'payment_status'), 'status' => $this->text($session, 'status')]),
			customerRef: $this->text($session, 'customer'),
			tax: $taxed ? $this->money(['amount_tax' => $details['amount_tax'] ?? 0, 'currency' => $session['currency'] ?? ''], 'amount_tax') : null,
			orderTotal: $taxed ? $this->money($session, 'amount_subtotal') : null,
		);
	}

	/**
	 * A refund of a charge. The amount is this refund's, not the running
	 * total: the newest refund on the charge when Stripe lists them, else
	 * the difference from what had been refunded before.
	 *
	 * @param array<array-key,mixed> $charge
	 * @param array<array-key,mixed> $previous the charge's fields as they were before this event
	 */
	private function refunded(string $eventId, array $charge, array $previous): PaymentEvent
	{
		$list   = is_array($charge['refunds'] ?? null) && is_array($charge['refunds']['data'] ?? null) ? array_values(array_filter($charge['refunds']['data'], is_array(...))) : [];
		$newest = null;
		foreach ($list as $refund) {
			if ($newest === null || (int)($refund['created'] ?? 0) > (int)($newest['created'] ?? 0)) {
				$newest = $refund;
			}
		}

		$metadata = is_array($charge['metadata'] ?? null) ? $charge['metadata'] : [];
		$amount   = $newest !== null
			? (int)($newest['amount'] ?? 0)
			: (int)($charge['amount_refunded'] ?? 0) - (int)($previous['amount_refunded'] ?? 0);

		return new PaymentEvent(
			provider: self::ID,
			eventId: $eventId,
			type: PaymentEventType::Refunded,
			// When the charge does not name the order, the store finds it by the payment.
			orderId: $this->text($metadata, 'orderId'),
			amount: Money::of(max(0, $amount), strtoupper($this->text($charge, 'currency'))),
			providerRef: $newest !== null ? $this->text($newest, 'id') : $this->text($charge, 'id'),
			providerPaymentRef: $this->text($charge, 'payment_intent'),
		);
	}

	/** @param array<array-key,mixed> $dispute a Dispute. It names the payment, not the order. */
	private function dispute(string $eventId, PaymentEventType $type, array $dispute): PaymentEvent
	{
		$payment = $this->text($dispute, 'payment_intent');

		return new PaymentEvent(
			provider: self::ID,
			eventId: $eventId,
			type: $type,
			// A new dispute can reach the store before the payment event that
			// lets it find the order by the payment, so its order is looked up.
			// A dispute that is closing is long after: the payment finds it.
			orderId: $type === PaymentEventType::Disputed ? $this->orderOf($payment) : '',
			amount: $this->money($dispute, 'amount'),
			providerRef: $this->text($dispute, 'id'),
			providerPaymentRef: $payment,
			summary: array_filter(['reason' => $this->text($dispute, 'reason'), 'status' => $this->text($dispute, 'status')]),
		);
	}

	/**
	 * The order a payment was taken for: checkout writes its id on the
	 * payment (`payment_intent_data.metadata`). '' for a payment another
	 * system took on the same Stripe account, or when Stripe cannot be asked;
	 * the store then looks for the order by the payment, as it always has.
	 */
	private function orderOf(string $paymentIntent): string
	{
		if ($paymentIntent === '') {
			return '';
		}

		try {
			$intent = $this->client->get('/payment_intents/' . rawurlencode($paymentIntent));
		} catch (ProviderException) {
			return '';
		}

		$metadata = is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [];

		return $this->text($metadata, 'orderId');
	}

	/** @param array<array-key,mixed> $object */
	private function money(array $object, string $key): Money
	{
		$amount = $object[$key] ?? 0;

		return Money::of(is_numeric($amount) ? max(0, (int)$amount) : 0, strtoupper($this->text($object, 'currency')));
	}

	/** @param array<array-key,mixed> $object */
	private function text(array $object, string $key): string
	{
		$value = $object[$key] ?? '';

		return is_string($value) ? $value : '';
	}
}
