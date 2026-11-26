<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\RefundRequest;
use TotalCMS\Domain\Commerce\Payment\RefundResult;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Money\Money;

/**
 * Asking the payment provider to give money back.
 *
 * A request changes nothing about what the order says was refunded. It asks
 * the provider, and writes a line in the order's ledger: `pending` when the
 * provider took the request, `failed` when it refused. The order's
 * `refundedTotal` and its status change only when the provider's own
 * webhook says the money has gone back, so the store never shows money
 * returned that was not.
 */
final readonly class RefundService
{
	private const TYPE = 'refund';

	private const PENDING = 'pending';

	/** The paid states a refund can be asked for. A disputed payment is in the bank's hands. */
	private const REFUNDABLE = ['paid', 'partially_refunded'];

	public function __construct(
		private OrderRepository $orders,
		private PaymentProviderRegistry $providers,
		private TransactionLedger $ledger,
	) {
	}

	/**
	 * Ask for $amount (in minor units) of an order to be refunded.
	 *
	 * @param bool $restock whether the items are coming back into stock; kept with the request, and acted on when the refund is confirmed
	 *
	 * @throws CommerceException no such order, nothing paid to refund, an amount that is not there, or a provider that cannot refund
	 *
	 * @return RefundResult whether the provider took the request; `error` is the provider's own code when it did not
	 */
	public function request(string $orderId, int $amount, string $reason, bool $restock, Actor $by): RefundResult
	{
		$order    = $this->orders->find($orderId) ?? throw CommerceException::orderNotFound();
		$provider = $this->providerFor($order);
		$left     = $this->refundable($order);
		if (!in_array($order->paymentStatus(), self::REFUNDABLE, true) || $order->providerPaymentRef() === '') {
			throw CommerceException::orderNotPaid();
		}
		if ($amount < 1 || $amount > $left) {
			throw CommerceException::refundAmountInvalid();
		}
		if ($amount < $left && !$provider->capabilities()->partialRefunds) {
			throw CommerceException::partialRefundUnsupported();
		}

		$reason = mb_substr(trim($reason), 0, 500);
		$money  = Money::of($amount, $order->currency());
		// One key per request. A request sent twice (a double click, a retry
		// after a timeout) carries the same key, and the provider refunds once.
		$key    = 'refund-' . $order->id() . '-' . (count($this->requests($order)) + 1);
		$result = $provider->refund(new RefundRequest($order->id(), $order->providerPaymentRef(), $money, $reason, $key));

		$this->ledger->record(
			$order,
			self::TYPE,
			$result->accepted ? self::PENDING : 'failed',
			$money,
			$result->providerRefundRef,
			$key,
			$this->actor($by),
			array_filter(['reason' => $reason, 'error' => $result->error, 'lines' => $restock ? $this->quantities($order) : '']) + ['restock' => $restock],
		);

		if (!$result->accepted) {
			$this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['needsAttention' => 'refund_failed']));
		}

		return $result;
	}

	/**
	 * How much of an order can still be asked back, in minor units: what was
	 * paid, less what the provider has confirmed refunded, less what has
	 * been asked for and not yet confirmed.
	 */
	public function refundable(OrderData $order): int
	{
		if (!in_array($order->paymentStatus(), self::REFUNDABLE, true)) {
			return 0;
		}

		return max(0, $this->paid($order) - $order->refundedTotal() - $this->pending($order));
	}

	/**
	 * How much has been asked back and not yet confirmed by the provider,
	 * in minor units.
	 *
	 * The ledger keeps every request, confirmed or not, so what is still on
	 * its way is what was asked for beyond what has been confirmed. A refund
	 * made at the provider itself is confirmed without a request, and can
	 * make this come out low; the provider then refuses anything beyond what
	 * is really left.
	 */
	public function pending(OrderData $order): int
	{
		$asked = 0;
		foreach ($this->requests($order) as $line) {
			$asked += ($line['status'] ?? '') === self::PENDING && is_numeric($line['amount'] ?? null) ? (int)$line['amount'] : 0;
		}

		return max(0, $asked - $order->refundedTotal());
	}

	/**
	 * What the provider was paid for the order, in the order's currency:
	 * its total, or less when staff accepted a payment that was for less.
	 * Nothing when that payment was in another currency: an amount of the
	 * order's currency cannot be asked back from it, so it is refunded at
	 * the provider.
	 */
	private function paid(OrderData $order): int
	{
		$held = $order->heldPayment();

		return $held !== null && $held['currency'] !== $order->currency() ? 0 : $order->paidTotal();
	}

	/** Whether a refund can be asked for at all: there is something left, and the provider does refunds. */
	public function available(OrderData $order): bool
	{
		$provider = $this->providers->get($order->provider());

		return $provider instanceof PaymentProvider && $provider->capabilities()->refunds
			&& $order->providerPaymentRef() !== '' && $this->refundable($order) > 0;
	}

	/** @throws CommerceException when the order's provider is gone or does not do refunds */
	private function providerFor(OrderData $order): PaymentProvider
	{
		$provider = $this->providers->get($order->provider());

		return $provider instanceof PaymentProvider && $provider->capabilities()->refunds ? $provider : throw CommerceException::refundsUnsupported();
	}

	/**
	 * The ledger lines of refunds staff asked for, taken or refused. A
	 * refund the provider's webhook reports is not one of them.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function requests(OrderData $order): array
	{
		return array_values(array_filter(
			$this->ledger->forOrder($order->id()),
			static fn (array $line): bool => ($line['type'] ?? '') === self::TYPE && !str_starts_with((string)($line['actor'] ?? ''), 'webhook:'),
		));
	}

	/**
	 * What was bought, as the ledger can hold it: a summary keeps plain
	 * values only, so the lines are written `lineId:qty,lineId:qty`.
	 */
	private function quantities(OrderData $order): string
	{
		$quantities = [];
		foreach ($order->lines() as $lineId => $line) {
			$quantities[] = $lineId . ':' . (is_numeric($line['qty'] ?? null) ? (int)$line['qty'] : 0);
		}

		return implode(',', $quantities);
	}

	/** Who asked, as the ledger names them. */
	private function actor(Actor $by): string
	{
		return $by->userId !== '' ? "user:{$by->authCollection}/{$by->userId}" : $by->kind->value;
	}
}
