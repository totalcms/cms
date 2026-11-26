<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * The words the admin uses for an order's states, and how each is colored.
 *
 * One place, so the list, the order page and the dashboard say the same
 * thing about the same order. A value this does not know is shown as it is
 * stored: a state added later is never hidden.
 */
final readonly class OrderLabels
{
	/** Payment status → [label, badge tone]. */
	private const PAYMENT = [
		'pending'            => ['Pending', 'muted'],
		'paid'               => ['Paid', 'success'],
		'failed'             => ['Failed', 'danger'],
		'expired'            => ['Expired', 'muted'],
		'review'             => ['In review', 'warning'],
		'partially_refunded' => ['Part refunded', 'accent'],
		'refunded'           => ['Refunded', 'accent'],
		'disputed'           => ['Disputed', 'danger'],
	];

	/** Fulfillment status → [label, badge tone]. */
	private const FULFILLMENT = [
		'unfulfilled'  => ['Unfulfilled', 'warning'],
		'partial'      => ['Part fulfilled', 'warning'],
		'fulfilled'    => ['Fulfilled', 'success'],
		'not_required' => ['Nothing to send', 'muted'],
	];

	/** Why an order needs someone → [a few words for the list, a sentence for the order's page]. */
	private const ATTENTION = [
		'amount_mismatch'             => ['Payment mismatch', 'The payment did not match the order. Nothing has been sent. Check the amount at the payment provider. Then a super admin can accept the payment here, or refund it at the provider.'],
		'paid_after_expiry'           => ['Paid after expiry', 'The buyer paid after this checkout had expired. Nothing has been sent. A super admin can accept the payment here, or refund it at the payment provider.'],
		'provider_mismatch'           => ['Wrong provider', 'This order was paid through a payment provider other than the one it was started with. Nothing has been sent. A super admin can accept the payment here, or refund it at that provider.'],
		'oversold'                    => ['Oversold', 'More was sold than was in stock. The buyer has paid: restock and send it, or refund it.'],
		'refund_failed'               => ['Refund failed', 'The payment provider refused a refund. No money has moved. Try again, or refund it at the provider.'],
		'disputed'                    => ['Disputed', 'The buyer has disputed this payment with their bank. Answer the dispute at the payment provider.'],
		'grant_failed'                => ['Access not granted', 'The buyer paid for access that could not be granted. Run fulfillment again once the account exists.'],
		OrderQuery::INCOMPLETE        => ['Not delivered', 'This order was paid more than 15 minutes ago and something in it has still not been delivered.'],
	];

	/** Ledger line type → label. */
	private const LEDGER = [
		'checkout_created' => 'Checkout started',
		'payment'          => 'Payment',
		'payment_accepted' => 'Payment accepted',
		'refund'           => 'Refund',
		'dispute'          => 'Dispute',
	];

	/** @return array{label: string, tone: string} */
	public function payment(string $status): array
	{
		return $this->badge(self::PAYMENT, $status);
	}

	/**
	 * How an order's fulfillment reads. Nothing is owed on an order nobody
	 * has paid for, so its "unfulfilled" is not a warning.
	 *
	 * @return array{label: string, tone: string}
	 */
	public function fulfillment(string $status, bool $paid = true): array
	{
		$badge = $this->badge(self::FULFILLMENT, $status);

		return $paid ? $badge : ['label' => $badge['label'], 'tone' => 'muted'];
	}

	/** A few words on why an order needs someone, or '' when it does not. */
	public function attention(string $reason): string
	{
		return $reason === '' ? '' : (self::ATTENTION[$reason][0] ?? $this->words($reason));
	}

	/** What to do about it, as a sentence, or '' when there is nothing to do. */
	public function attentionHelp(string $reason): string
	{
		return $reason === '' ? '' : (self::ATTENTION[$reason][1] ?? 'This order needs a look: ' . $this->words($reason) . '.');
	}

	public function ledger(string $type): string
	{
		return self::LEDGER[$type] ?? ucfirst($this->words($type));
	}

	/**
	 * Every payment and fulfillment status, for a filter's choices.
	 *
	 * @return array{payment: array<string,string>, fulfillment: array<string,string>}
	 */
	public function choices(): array
	{
		return [
			'payment'     => array_map(static fn (array $badge): string => $badge[0], self::PAYMENT),
			'fulfillment' => array_map(static fn (array $badge): string => $badge[0], self::FULFILLMENT),
		];
	}

	/**
	 * @param array<string,array{0: string, 1: string}> $known
	 *
	 * @return array{label: string, tone: string}
	 */
	private function badge(array $known, string $status): array
	{
		$badge = $known[$status] ?? [ucfirst($this->words($status)), 'muted'];

		return ['label' => $badge[0], 'tone' => $badge[1]];
	}

	private function words(string $code): string
	{
		return str_replace('_', ' ', $code);
	}
}
