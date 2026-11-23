<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderLabels;
use TotalCMS\Domain\Commerce\Order\OrderQuery;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;
use TotalCMS\Domain\Commerce\Service\RefundService;
use TotalCMS\Domain\Money\Money;

/**
 * What an order's page in the admin shows, worked out in one place: the
 * page itself and every action that redraws it are given the same thing.
 *
 * The page is handed the order's fields one by one. The buyer's link to
 * the order and the provider's client secret are not among them.
 */
final readonly class OrderPageView
{
	public function __construct(
		private OrderQuery $query,
		private OrderLabels $labels,
		private OrderStateMachine $machine,
		private TransactionLedger $ledger,
		private CommerceSettings $settings,
		private ActorContext $actors,
		private PaymentProviderRegistry $providers,
		private RefundService $refunds,
		private MoneyFilter $money,
	) {
	}

	/** @return array<string,mixed> */
	public function of(OrderData $order): array
	{
		$holds  = $order->holdsPayment();
		$reason = $this->query->attentionReason($order);

		$lines = [];
		$toDo  = 0;
		foreach ($order->lines() as $line) {
			$needsWork = $this->machine->lineNeedsWork($line);
			$status    = is_string($line['fulfillmentStatus'] ?? null) ? $line['fulfillmentStatus'] : '';
			$open      = $needsWork && $status !== OrderStateMachine::FULFILLED;
			$toDo += $open ? 1 : 0;
			$lines[] = $line + [
				'needsWork' => $needsWork,
				'open'      => $open,
				'badge'     => $needsWork ? $this->labels->fulfillment($status, $holds) : $this->labels->fulfillment('not_required'),
			];
		}

		$ledger = [];
		foreach ($this->ledger->forOrder($order->id()) as $row) {
			$type   = is_string($row['type'] ?? null) ? $row['type'] : '';
			$status = is_string($row['status'] ?? null) ? $row['status'] : '';
			// A refund staff asked for is its own line, beside the one the
			// provider's webhook writes when the money has gone back. It
			// stays as written, so it says what happened to the request.
			$asked    = $type === 'refund' && !str_starts_with((string)($row['actor'] ?? ''), 'webhook:');
			$ledger[] = [
				'label'      => $asked ? 'Refund requested' : $this->labels->ledger($type),
				'statusText' => $asked ? ($status === 'failed' ? 'refused by the provider' : 'taken by the provider') : str_replace('_', ' ', $status),
			] + $row;
		}

		$left = $this->refunds->refundable($order);
		$held = $order->paymentStatus() === 'review' ? $order->heldMoney() : null;

		return [
			'order'       => $order,
			'lines'       => $lines,
			'ledger'      => $ledger,
			'payment'     => $this->labels->payment($order->paymentStatus()),
			'fulfillment' => $this->labels->fulfillment($order->fulfillmentStatus(), $holds),
			'attention'   => ['label' => $this->labels->attention($reason), 'help' => $this->labels->attentionHelp($reason)],
			'prefix'      => $this->settings->orderNumberPrefix(),
			// The provider's own name; its id when the provider is no longer installed.
			'providerLabel' => $this->providers->get($order->provider())?->label() ?? $order->provider(),
			'noteMax'     => OrderAdmin::NOTE_MAX,
			'refund'      => [
				// As a person writes it ("29.98"), for the form's amount box.
				'left'          => Money::of($left, $order->currency())->toDecimal(),
				'leftFormatted' => ($this->money)($left, $order->currency()),
				// The smallest amount the currency has: 0.01, or 1 where it has no cents.
				'step'          => Money::of(1, $order->currency())->toDecimal(),
			],
			'can'         => $this->allowed($order, $toDo, $left),
			// What the provider says it was paid, for an order held for review.
			'held'        => $held instanceof Money ? ($this->money)($held->amount(), $held->currency()) : '',
		];
	}

	/**
	 * What this user may do to the order now. What undoes or repeats a sale,
	 * or settles money that did not match, is for a super admin; the routes
	 * hold the same line.
	 *
	 * @param int $toDo how many lines are still to send
	 * @param int $left how much can still be refunded
	 *
	 * @return array<string,bool>
	 */
	private function allowed(OrderData $order, int $toDo, int $left): array
	{
		$holds = $order->holdsPayment();
		$super = $this->actors->current()->isTrusted();

		return [
			'refund'    => $super && $left > 0 && $this->refunds->available($order),
			'fulfill'   => $holds && $toDo > 0,
			'receipt'   => $holds && $this->settings->receiptMailer() !== '',
			'refulfill' => $holds && $super,
			'cancel'    => $super && in_array($order->paymentStatus(), ['pending', 'failed'], true),
			'accept'    => $super && $order->paymentStatus() === 'review',
		];
	}
}
