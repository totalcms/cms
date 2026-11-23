<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Payment\OrderView;
use TotalCMS\Domain\Money\Money;

/**
 * A stored order, read through names instead of array keys.
 *
 * It does not change: every `with…` gives back a new one, and nothing is
 * written until {@see OrderRepository} saves it. Every amount is minor units
 * of {@see currency()}.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one reader per field of
 * @SuppressWarnings("PHPMD.ExcessivePublicCount") the order record, so no
 * @SuppressWarnings("PHPMD.TooManyMethods")       caller spells a key
 */
final readonly class OrderData
{
	public const KIND_CHECKOUT = 'checkout';
	public const KIND_RENEWAL  = 'renewal';

	/** Where an order keeps the payment it was held for review with: flags.heldPayment. */
	public const HELD_PAYMENT = 'heldPayment';

	/** @param array<string,mixed> $data the order as stored */
	public function __construct(private array $data)
	{
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return $this->data;
	}

	// ─── Identity ──────────────────────────────────────────────────────────

	public function id(): string
	{
		return $this->text('id');
	}

	public function number(): int
	{
		return $this->whole('number');
	}

	/** checkout | renewal */
	public function kind(): string
	{
		return $this->text('kind') ?: self::KIND_CHECKOUT;
	}

	public function isRenewal(): bool
	{
		return $this->kind() === self::KIND_RENEWAL;
	}

	public function subscriptionId(): string
	{
		return $this->text('subscriptionId');
	}

	/** The secret that signs this order's links for a buyer without an account. Never shown or sent. */
	public function accessToken(): string
	{
		return $this->text('accessToken');
	}

	/** The cart this order was started from, so a later checkout of the same cart can find it. */
	public function cartKey(): string
	{
		$key = $this->metadata()['cartKey'] ?? '';

		return is_string($key) ? $key : '';
	}

	// ─── Status ────────────────────────────────────────────────────────────

	/** open | completed | cancelled */
	public function status(): string
	{
		return $this->text('status');
	}

	/** pending | paid | failed | expired | review | partially_refunded | refunded | disputed */
	public function paymentStatus(): string
	{
		return $this->text('paymentStatus');
	}

	/** unfulfilled | partial | fulfilled | not_required */
	public function fulfillmentStatus(): string
	{
		return $this->text('fulfillmentStatus');
	}

	/** '' or a reason: amount_mismatch, paid_after_expiry, provider_mismatch, oversold, fulfillment_incomplete, refund_failed, disputed. */
	public function needsAttention(): string
	{
		return $this->text('needsAttention');
	}

	/** @return array<string,mixed> the side effects that have run */
	public function flags(): array
	{
		return $this->map('flags');
	}

	public function hasFlag(string $flag): bool
	{
		return ($this->flags()[$flag] ?? false) === true;
	}

	// ─── Who ───────────────────────────────────────────────────────────────

	public function email(): string
	{
		return $this->text('email');
	}

	public function customerCollection(): string
	{
		return $this->text('customerCollection');
	}

	public function customerId(): string
	{
		return $this->text('customerId');
	}

	/** @return array<string,mixed> */
	public function shippingAddress(): array
	{
		return $this->map('shippingAddress');
	}

	/** @return array<string,mixed> */
	public function billingAddress(): array
	{
		return $this->map('billingAddress');
	}

	// ─── What ──────────────────────────────────────────────────────────────

	/** @return array<string,array<string,mixed>> line id => the line as it was sold */
	public function lines(): array
	{
		$lines = [];
		foreach ($this->map('lines') as $id => $line) {
			if (is_array($line)) {
				$lines[(string)$id] = $line;
			}
		}

		return $lines;
	}

	/** @return array<string,mixed>|null */
	public function line(string $lineId): ?array
	{
		return $this->lines()[$lineId] ?? null;
	}

	/** @return array<string,mixed> the checkout attributes the settings allowed */
	public function attributes(): array
	{
		return $this->map('attributes');
	}

	/** @return array<string,mixed> written by order-building listeners */
	public function metadata(): array
	{
		return $this->map('metadata');
	}

	// ─── Money ─────────────────────────────────────────────────────────────

	public function currency(): string
	{
		return $this->text('currency');
	}

	public function subtotal(): int
	{
		return $this->whole('subtotal');
	}

	public function discountTotal(): int
	{
		return $this->whole('discountTotal');
	}

	public function shippingTotal(): int
	{
		return $this->whole('shippingTotal');
	}

	public function taxTotal(): int
	{
		return $this->whole('taxTotal');
	}

	public function grandTotal(): int
	{
		return $this->whole('grandTotal');
	}

	public function grandTotalMoney(): Money
	{
		return Money::of($this->grandTotal(), $this->currency());
	}

	public function refundedTotal(): int
	{
		return $this->whole('refundedTotal');
	}

	/**
	 * The payment an order went to review with, as the provider reported
	 * it: where the money is, and how much of it is still there. It stays
	 * on the order once staff accept the payment, so a refund never asks
	 * for more than was paid. Null for an order that was never in review.
	 *
	 * @return array{provider: string, paymentRef: string, amount: int, currency: string, subscriptionRef: string, customerRef: string}|null
	 */
	public function heldPayment(): ?array
	{
		$held = $this->flags()[self::HELD_PAYMENT] ?? null;
		if (!is_array($held)) {
			return null;
		}
		$text = static fn (string $key): string => is_string($held[$key] ?? null) ? $held[$key] : '';

		return [
			'provider'        => $text('provider'),
			'paymentRef'      => $text('paymentRef'),
			'amount'          => is_numeric($held['amount'] ?? null) ? max(0, (int)$held['amount']) : 0,
			'currency'        => $text('currency'),
			'subscriptionRef' => $text('subscriptionRef'),
			'customerRef'     => $text('customerRef'),
		];
	}

	/** What the provider says it holds for an order that went to review, or null for an order that never did. */
	public function heldMoney(): ?Money
	{
		$held = $this->heldPayment();

		return $held === null || $held['currency'] === '' ? null : Money::of($held['amount'], $held['currency']);
	}

	/**
	 * How much of the order's total the provider was paid: all of it,
	 * unless staff accepted a payment that was for less.
	 */
	public function paidTotal(): int
	{
		$held = $this->heldPayment();

		return $held !== null && $held['currency'] === $this->currency() ? min($this->grandTotal(), $held['amount']) : $this->grandTotal();
	}

	/** none | exclusive | inclusive */
	public function taxMode(): string
	{
		return $this->text('taxMode');
	}

	/** @return list<array<string,mixed>> */
	public function taxLines(): array
	{
		return array_values(array_filter($this->map('taxLines'), is_array(...)));
	}

	/** @return array<string,mixed> {rateId, label, amount}, or [] when nothing shipped */
	public function shippingLine(): array
	{
		return $this->map('shippingLine');
	}

	public function discountCode(): string
	{
		return $this->text('discountCode');
	}

	/** @return array<string,mixed> {code, type, value, amount, shippingWaived, recurring}, or [] */
	public function discountLine(): array
	{
		return $this->map('discountLine');
	}

	// ─── Payment ───────────────────────────────────────────────────────────

	public function provider(): string
	{
		return $this->text('provider');
	}

	public function providerSessionRef(): string
	{
		return $this->text('providerSessionRef');
	}

	public function providerPaymentRef(): string
	{
		return $this->text('providerPaymentRef');
	}

	/** On-page checkout only. Never shown, never in an event. */
	public function providerClientSecret(): string
	{
		return $this->text('providerClientSecret');
	}

	// ─── Staff ─────────────────────────────────────────────────────────────

	public function notes(): string
	{
		return $this->text('notes');
	}

	public function trackingNumber(): string
	{
		return $this->text('trackingNumber');
	}

	public function trackingUrl(): string
	{
		return $this->text('trackingUrl');
	}

	/** @return list<string> */
	public function tags(): array
	{
		return array_values(array_filter($this->map('tags'), is_string(...)));
	}

	// ─── When ──────────────────────────────────────────────────────────────

	public function created(): string
	{
		return $this->text('created');
	}

	public function paidAt(): string
	{
		return $this->text('paidAt');
	}

	public function updated(): string
	{
		return $this->text('updated');
	}

	// ─── Changes ───────────────────────────────────────────────────────────

	/**
	 * The same order with these fields changed.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function with(array $changes): self
	{
		unset($changes['id']);

		return new self($changes + $this->data);
	}

	public function withStatuses(string $payment, string $fulfillment, string $status): self
	{
		return $this->with(['paymentStatus' => $payment, 'fulfillmentStatus' => $fulfillment, 'status' => $status]);
	}

	/** Mark a side effect as done, so a retry does not do it again. */
	public function withFlag(string $flag): self
	{
		return $this->with(['flags' => [$flag => true] + $this->flags()]);
	}

	/**
	 * The same order with one line changed. Nothing changes for a line it does not have.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function withLine(string $lineId, array $changes): self
	{
		$lines = $this->lines();
		if (!isset($lines[$lineId])) {
			return $this;
		}
		unset($changes['id']);
		$lines[$lineId] = $changes + $lines[$lineId];

		return $this->with(['lines' => $lines]);
	}

	/**
	 * Whether the store holds money for this order: paid, and not all of it
	 * given back. What is owed to a buyer is owed on such an order only.
	 */
	public function holdsPayment(): bool
	{
		return in_array($this->paymentStatus(), ['paid', 'partially_refunded', 'disputed'], true);
	}

	/**
	 * The order as its buyer may see it, for a page or a template: without
	 * the secret that signs its links, the provider's client secret, staff
	 * notes, or the engine's own bookkeeping.
	 *
	 * @return array<string,mixed>
	 */
	public function forBuyer(): array
	{
		return array_diff_key($this->data, array_flip(['accessToken', 'providerClientSecret', 'flags', 'metadata', 'notes', 'tags']));
	}

	/** The order as a payment provider may see it: no secret, and nothing it could change. */
	public function view(): OrderView
	{
		return new OrderView(
			id: $this->id(),
			number: $this->number(),
			email: $this->email(),
			grandTotal: $this->grandTotalMoney(),
			paymentStatus: $this->paymentStatus(),
			provider: $this->provider(),
			providerSessionRef: $this->providerSessionRef(),
			providerPaymentRef: $this->providerPaymentRef(),
		);
	}

	private function text(string $key): string
	{
		$value = $this->data[$key] ?? '';

		return is_scalar($value) ? (string)$value : '';
	}

	private function whole(string $key): int
	{
		$value = $this->data[$key] ?? 0;

		return is_numeric($value) ? (int)$value : 0;
	}

	/** @return array<array-key,mixed> */
	private function map(string $key): array
	{
		$value = $this->data[$key] ?? [];

		return is_array($value) ? $value : [];
	}
}
