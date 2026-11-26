<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\PayPal;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Payment\CheckoutLineSummary;
use TotalCMS\Domain\Commerce\Payment\CheckoutRequest;
use TotalCMS\Domain\Commerce\Payment\CheckoutSession;
use TotalCMS\Domain\Commerce\Payment\OrderView;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\ProviderCapabilities;
use TotalCMS\Domain\Commerce\Payment\ProviderEvent;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Payment\RefundResult;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Webhook\RawBody;
use TotalCMS\Domain\Webhook\WebhookVerifier;

/**
 * PayPal as a way to pay: the buyer approves the payment on PayPal's own
 * page, the store captures it when they come back, and PayPal's signed
 * webhook tells the store how it went.
 *
 * PayPal does it in two steps where a card payment has one. Approving is
 * the buyer's promise; the money moves only when the store captures the
 * order. So the capture is asked for wherever the store first learns of the
 * approval: when the buyer comes back, when PayPal's webhook says they
 * approved, and when the store asks PayPal about an order that is still
 * waiting. All three use one key, so the order is captured once.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") one provider, one class:
 *                                                    the branches are PayPal's
 *                                                    event types
 */
final readonly class PayPalProvider implements PaymentProvider
{
	public const ID = 'paypal';

	/**
	 * The currencies PayPal takes and writes with the decimals the store
	 * does. The forint and the Taiwan dollar are left out: PayPal takes them
	 * only as whole numbers.
	 */
	private const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'USD'];

	/** PayPal's own pages. A link to pay that goes anywhere else is not followed. */
	private const PAY_PAGE = '/^https:\/\/www\.(?:sandbox\.)?paypal\.com\//';

	/** The longest product name, and the longest note on a refund, that PayPal takes. */
	private const NAME_LENGTH = 127;
	private const NOTE_LENGTH = 255;

	/** How a dispute ended, by PayPal's outcome codes. Any other leaves the order disputed, for a person to look at. */
	private const WON  = ['RESOLVED_SELLER_FAVOUR', 'CANCELED_BY_BUYER', 'DENIED', 'RESOLVED_WITH_PAYOUT'];
	private const LOST = ['RESOLVED_BUYER_FAVOUR', 'ACCEPTED'];

	/** What PayPal answers about an order it no longer has. */
	private const GONE = ['RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID'];

	public function __construct(
		private PayPalClient $client,
		private string $clientId,
		private string $secret,
		private string $webhookId,
		private LoggerInterface $logger,
	) {
	}

	public function id(): string
	{
		return self::ID;
	}

	public function label(): string
	{
		return 'PayPal';
	}

	public function capabilities(): ProviderCapabilities
	{
		return new ProviderCapabilities();
	}

	/**
	 * Ready when the app's client id, its secret and the webhook's id are
	 * filled in. Without the webhook id no event could be believed, and an
	 * order would be paid for and never marked paid.
	 */
	public function isConfigured(): bool
	{
		return $this->clientId !== '' && $this->secret !== '' && $this->webhookId !== '';
	}

	public function supportsCurrency(string $currency): bool
	{
		return in_array(strtoupper($currency), self::CURRENCIES, true);
	}

	public function createCheckout(CheckoutRequest $request): CheckoutSession
	{
		if ($request->mode !== 'payment') {
			throw new ProviderException('PayPal is not set up for that kind of checkout yet.', 'unsupported');
		}

		$currency = $request->total->currency();
		$zero     = Money::zero($currency);
		$items    = $zero;
		$discount = $zero;
		foreach ($request->lines as $line) {
			$items    = $items->add($line->unitAmount->multiply($line->qty));
			$discount = $discount->add($line->discount);
		}

		$sum = $items->subtract($discount)->add($request->shipping)->add($request->tax);
		if (!$sum->equals($request->total)) {
			// The buyer must never be asked for anything but the order's total.
			throw new ProviderException("The parts of order {$request->orderNumber} come to {$sum->amount()}, not the order's total of {$request->total->amount()}.", 'total_mismatch');
		}

		// Items stay at full price and the discount is one line of the
		// breakdown, which is how PayPal's page shows a discount.
		$breakdown = ['item_total' => $this->amount($items)];
		foreach (['discount' => $discount, 'shipping' => $request->shipping, 'tax_total' => $request->tax] as $part => $money) {
			if (!$money->isZero()) {
				$breakdown[$part] = $this->amount($money);
			}
		}

		$order = $this->client->post('/v2/checkout/orders', [
			'intent'         => 'CAPTURE',
			'purchase_units' => [[
				'reference_id' => $request->orderId,
				// Comes back on the payment and on every later event about it.
				'custom_id'    => $request->orderId,
				// PayPal refuses a second payment under an invoice id it has seen.
				// The order number alone repeats across stores on one account.
				'invoice_id'   => $request->orderNumber . '-' . substr($request->orderId, 0, 8),
				'amount'       => $this->amount($request->total) + ['breakdown' => $breakdown],
				'items'        => array_map(fn (CheckoutLineSummary $line): array => [
					'name'        => mb_substr($line->title, 0, self::NAME_LENGTH),
					'quantity'    => (string)$line->qty,
					'unit_amount' => $this->amount($line->unitAmount),
				], $request->lines),
			]],
			'payment_source' => ['paypal' => ['experience_context' => [
				'return_url'          => $request->successUrl,
				'cancel_url'          => $request->cancelUrl,
				// The button on PayPal's page says "Pay now": nothing is left to confirm afterward.
				'user_action'         => 'PAY_NOW',
				// The store has the address an order ships to; PayPal is not asked to collect another.
				'shipping_preference' => 'NO_SHIPPING',
			]]],
		], $request->idempotencyKey);

		$id  = $this->text($order, 'id');
		$url = $this->link($order, 'payer-action') ?: $this->link($order, 'approve');
		if ($id === '' || preg_match(self::PAY_PAGE, $url) !== 1) {
			throw new ProviderException('PayPal answered without a page to pay on.', 'invalid_response');
		}

		return new CheckoutSession($id, $url);
	}

	/**
	 * The buyer is back from PayPal, which adds `token` (its order) to the
	 * address. Only this order's own PayPal order is captured, and only
	 * while the order is waiting to be paid. The order is not changed here:
	 * PayPal's webhook says how the capture went.
	 */
	public function completeReturn(OrderView $order, ServerRequestInterface $request): void
	{
		$token = $request->getQueryParams()['token'] ?? '';
		if ($order->paymentStatus !== 'pending' || $order->providerSessionRef === '' || $token !== $order->providerSessionRef) {
			return;
		}

		$this->capture($order->providerSessionRef, $order->id);
	}

	public function webhookVerifier(): WebhookVerifier
	{
		return new PayPalWebhookVerifier($this->client, $this->webhookId);
	}

	public function parseWebhook(ServerRequestInterface $request): ?ProviderEvent
	{
		$event    = json_decode(RawBody::of($request), true);
		$id       = is_array($event) ? ($event['id'] ?? null) : null;
		$type     = is_array($event) ? ($event['event_type'] ?? null) : null;
		$resource = is_array($event) ? ($event['resource'] ?? null) : null;
		if (!is_string($id) || $id === '' || !is_string($type) || !is_array($resource)) {
			return null;
		}

		return match ($type) {
			'CHECKOUT.ORDER.APPROVED'   => $this->approved($resource),
			'PAYMENT.CAPTURE.COMPLETED' => $this->captured(PaymentEventType::Paid, $resource, ''),
			'PAYMENT.CAPTURE.DENIED'    => $this->captured(PaymentEventType::Failed, $resource, $id),
			'PAYMENT.CAPTURE.REFUNDED'  => $this->refunded($id, $resource),
			'CUSTOMER.DISPUTE.CREATED'  => $this->dispute($id, PaymentEventType::Disputed, $resource),
			'CUSTOMER.DISPUTE.RESOLVED' => match (true) {
				in_array($this->outcome($resource), self::WON, true)  => $this->dispute($id, PaymentEventType::DisputeWon, $resource),
				in_array($this->outcome($resource), self::LOST, true) => $this->dispute($id, PaymentEventType::DisputeLost, $resource),
				default                                               => null,
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
			$order = $this->client->get('/v2/checkout/orders/' . rawurlencode($providerSessionRef));
		} catch (ProviderException $e) {
			// PayPal keeps an order nobody paid for a few hours, then forgets it.
			return in_array($e->providerCode, self::GONE, true)
				? new PaymentEvent(self::ID, "order-{$providerSessionRef}-expired", PaymentEventType::Expired, '', Money::zero('USD'), $providerSessionRef)
				: null;
		}

		// Approved and never captured: the buyer did not come back. The store
		// takes the payment it was promised, and reports that.
		if ($this->text($order, 'status') === 'APPROVED') {
			$unit = $this->unit($order);

			try {
				$order = $this->capture($providerSessionRef, $this->text($unit, 'custom_id') ?: $providerSessionRef);
			} catch (ProviderException $e) {
				$this->logger->warning('PayPal would not capture an order its buyer approved', ['orderId' => $this->text($unit, 'custom_id'), 'code' => $e->providerCode]);

				return null;
			}
		}

		return $this->text($order, 'status') === 'COMPLETED' ? $this->paidOrder($order) : null;
	}

	public function refund(RefundRequest $request): RefundResult
	{
		try {
			$refund = $this->client->post('/v2/payments/captures/' . rawurlencode($request->providerPaymentRef) . '/refund', array_filter([
				'amount'        => ['value' => $request->amount->toDecimal(), 'currency_code' => $request->amount->currency()],
				'custom_id'     => $request->orderId,
				'note_to_payer' => mb_substr($request->reason, 0, self::NOTE_LENGTH),
			]), $request->idempotencyKey);
		} catch (ProviderException $e) {
			return new RefundResult(false, '', $e->providerCode);
		}

		return new RefundResult(true, $this->text($refund, 'id'));
	}

	/**
	 * Take the money for an order its buyer approved.
	 *
	 * @param string $orderId the store's order, which names the request: PayPal answers a repeat with the first capture
	 *
	 * @throws ProviderException
	 *
	 * @return array<string,mixed> PayPal's order, with the capture in it
	 */
	private function capture(string $providerSessionRef, string $orderId): array
	{
		return $this->client->post('/v2/checkout/orders/' . rawurlencode($providerSessionRef) . '/capture', [], 'capture-' . $orderId);
	}

	/**
	 * PayPal says the buyer approved an order. That is what a buyer who
	 * closes the window before coming back leaves behind, so it is captured
	 * here. Nothing is reported: the capture has an event of its own.
	 *
	 * @param array<array-key,mixed> $order
	 */
	private function approved(array $order): null
	{
		$ref     = $this->text($order, 'id');
		$orderId = $this->text($this->unit($order), 'custom_id');
		// PayPal sends this event late as well: by then the buyer has come
		// back, and the order it carries is captured already.
		if ($ref === '' || $this->text($order, 'status') !== 'APPROVED') {
			return null;
		}

		try {
			$this->capture($ref, $orderId !== '' ? $orderId : $ref);
		} catch (ProviderException $e) {
			// Most often the buyer came back and it is captured already.
			$this->logger->info('PayPal did not capture an approved order when its webhook arrived', ['orderId' => $orderId, 'code' => $e->providerCode]);
		}

		return null;
	}

	/**
	 * A paid event from PayPal's order, once its capture is complete.
	 *
	 * @param array<array-key,mixed> $order
	 */
	private function paidOrder(array $order): ?PaymentEvent
	{
		$unit     = $this->unit($order);
		$payments = is_array($unit['payments'] ?? null) && is_array($unit['payments']['captures'] ?? null) ? $unit['payments']['captures'] : [];
		$capture  = is_array($payments[0] ?? null) ? $payments[0] : [];
		if ($this->text($capture, 'status') !== 'COMPLETED') {
			return null;
		}

		return $this->captured(PaymentEventType::Paid, $capture + ['custom_id' => $this->text($unit, 'custom_id')], '', $this->text($order, 'id'));
	}

	/**
	 * An event about a capture: the payment itself.
	 *
	 * A paid capture is named by the capture, not by the webhook that told
	 * of it, so that hearing of it twice (the webhook, and the store asking
	 * PayPal) is one event.
	 *
	 * @param array<array-key,mixed> $capture
	 * @param string                 $eventId the webhook's id; '' to name the event by the capture
	 * @param string                 $ref     PayPal's order, when the caller has it
	 */
	private function captured(PaymentEventType $type, array $capture, string $eventId, string $ref = ''): PaymentEvent
	{
		$id      = $this->text($capture, 'id');
		$related = is_array($capture['supplementary_data'] ?? null) && is_array($capture['supplementary_data']['related_ids'] ?? null) ? $capture['supplementary_data']['related_ids'] : [];

		return new PaymentEvent(
			provider: self::ID,
			eventId: $eventId !== '' ? $eventId : 'capture-' . $id,
			type: $type,
			orderId: $this->text($capture, 'custom_id'),
			amount: $this->money($capture['amount'] ?? null),
			providerRef: $ref !== '' ? $ref : ($this->text($related, 'order_id') ?: $id),
			providerPaymentRef: $id,
			summary: array_filter(['status' => $this->text($capture, 'status')]),
		);
	}

	/**
	 * A refund of a capture. The refund names the capture it is of in its
	 * links, and that is how the store finds the order when the refund
	 * carries no order id.
	 *
	 * @param array<array-key,mixed> $refund
	 */
	private function refunded(string $eventId, array $refund): PaymentEvent
	{
		$capture = $this->link($refund, 'up');

		return new PaymentEvent(
			provider: self::ID,
			eventId: $eventId,
			type: PaymentEventType::Refunded,
			orderId: $this->text($refund, 'custom_id'),
			amount: $this->money($refund['amount'] ?? null),
			providerRef: $this->text($refund, 'id'),
			providerPaymentRef: preg_match('#/captures/([A-Za-z0-9]+)\z#', $capture, $found) === 1 ? $found[1] : '',
		);
	}

	/** @param array<array-key,mixed> $dispute */
	private function dispute(string $eventId, PaymentEventType $type, array $dispute): PaymentEvent
	{
		$transactions = is_array($dispute['disputed_transactions'] ?? null) ? $dispute['disputed_transactions'] : [];
		$transaction  = is_array($transactions[0] ?? null) ? $transactions[0] : [];

		return new PaymentEvent(
			provider: self::ID,
			eventId: $eventId,
			type: $type,
			orderId: $this->text($transaction, 'custom'),
			amount: $this->money($dispute['dispute_amount'] ?? null),
			providerRef: $this->text($dispute, 'dispute_id'),
			providerPaymentRef: $this->text($transaction, 'seller_transaction_id'),
			summary: array_filter(['reason' => $this->text($dispute, 'reason'), 'status' => $this->text($dispute, 'status'), 'outcome' => $this->outcome($dispute)]),
		);
	}

	/** @param array<array-key,mixed> $dispute */
	private function outcome(array $dispute): string
	{
		return is_array($dispute['dispute_outcome'] ?? null) ? $this->text($dispute['dispute_outcome'], 'outcome_code') : '';
	}

	/**
	 * The one purchase unit an order of the store's has.
	 *
	 * @param array<array-key,mixed> $order
	 *
	 * @return array<array-key,mixed>
	 */
	private function unit(array $order): array
	{
		$units = is_array($order['purchase_units'] ?? null) ? $order['purchase_units'] : [];

		return is_array($units[0] ?? null) ? $units[0] : [];
	}

	/**
	 * Where one of PayPal's links goes, by its `rel`. '' when there is none.
	 *
	 * @param array<array-key,mixed> $object
	 */
	private function link(array $object, string $rel): string
	{
		foreach (is_array($object['links'] ?? null) ? $object['links'] : [] as $link) {
			if (is_array($link) && ($link['rel'] ?? '') === $rel && is_string($link['href'] ?? null)) {
				return $link['href'];
			}
		}

		return '';
	}

	/**
	 * An amount as PayPal writes one: a decimal string and its currency.
	 *
	 * @return array{currency_code: string, value: string}
	 */
	private function amount(Money $money): array
	{
		return ['currency_code' => $money->currency(), 'value' => $money->toDecimal()];
	}

	/** An amount as PayPal wrote it, in the store's whole units. Nothing when it cannot be read. */
	private function money(mixed $amount): Money
	{
		$currency = is_array($amount) && is_string($amount['currency_code'] ?? null) ? strtoupper($amount['currency_code']) : '';
		$value    = is_array($amount) && is_string($amount['value'] ?? null) ? $amount['value'] : '';

		try {
			return (new MoneyConverter())->fromDecimal($value, $currency);
		} catch (\InvalidArgumentException|\OverflowException) {
			return Money::zero(preg_match('/^[A-Z]{3}\z/', $currency) === 1 ? $currency : 'USD');
		}
	}

	/** @param array<array-key,mixed> $object */
	private function text(array $object, string $key): string
	{
		$value = $object[$key] ?? '';

		return is_string($value) ? $value : '';
	}
}
