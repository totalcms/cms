<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Subscription\SubscriptionEventProcessor;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;

/**
 * What staff do to an order from the admin: mark lines sent, keep a note,
 * send the receipt again, cancel an order nobody paid for, accept a payment
 * held for review, run fulfillment again. Each is checked here, so the
 * admin's routes only carry the request.
 *
 * Every change is made under the order's lock, on the order as it is on
 * disk at that moment. None of them moves money: a refund is its own
 * service, and asks the payment provider.
 */
final readonly class OrderAdmin
{
	/** The longest note staff can keep on an order, in characters. */
	public const NOTE_MAX = 5000;

	private const FULFILLED = 'fulfilled';

	public function __construct(
		private OrderRepository $orders,
		private OrderFulfillment $fulfillment,
		private OrderStateMachine $machine,
		private ReceiptQueuer $receipts,
		private PaymentEventProcessor $payments,
		private DownloadIssuer $downloads,
		private SubscriptionEventProcessor $access,
	) {
	}

	/**
	 * Mark lines as sent, with the parcel's tracking if there is any. A
	 * line already marked stays as it is. Tracking left empty keeps what an
	 * earlier parcel saved.
	 *
	 * @param list<string> $lineIds
	 *
	 * @throws CommerceException no such order or line, no lines, an unpaid order, or a tracking link that is not one
	 */
	public function fulfill(string $orderId, array $lineIds, string $trackingNumber = '', string $trackingUrl = '', string $note = ''): OrderData
	{
		$trackingNumber = mb_substr(trim($trackingNumber), 0, 200);
		$trackingUrl    = trim($trackingUrl);
		$note           = mb_substr(trim($note), 0, 500);
		if ($lineIds === []) {
			throw CommerceException::linesRequired();
		}
		if ($trackingUrl !== '' && !$this->isWebAddress($trackingUrl)) {
			throw CommerceException::trackingUrlInvalid();
		}

		$this->orders->withLock($orderId, function (OrderData $order) use ($lineIds, $trackingNumber, $trackingUrl, $note): OrderData {
			if (!$order->holdsPayment()) {
				throw CommerceException::orderNotPaid();
			}

			foreach ($lineIds as $lineId) {
				$line = $order->line($lineId) ?? throw CommerceException::lineNotFound();
				if (($line['fulfillmentStatus'] ?? '') !== self::FULFILLED) {
					$order = $order->withLine($lineId, ['fulfillmentStatus' => self::FULFILLED, 'fulfillmentNote' => $note]);
				}
			}

			return $this->fulfillment->settle($order->with(array_filter(['trackingNumber' => $trackingNumber, 'trackingUrl' => $trackingUrl])));
		});
		// Outside the lock: listeners hear it, and may change the order themselves.
		$this->fulfillment->announce($orderId);

		return $this->orders->find($orderId) ?? throw CommerceException::orderNotFound();
	}

	/**
	 * Keep a note on the order, for staff only: the buyer never sees it.
	 * An empty note clears it.
	 *
	 * @throws CommerceException no such order, or a note longer than {@see NOTE_MAX}
	 */
	public function note(string $orderId, string $notes): OrderData
	{
		$notes = trim(str_replace("\r\n", "\n", $notes));
		if (mb_strlen($notes) > self::NOTE_MAX) {
			throw CommerceException::noteTooLong(self::NOTE_MAX);
		}

		return $this->orders->withLock($orderId, static fn (OrderData $order): OrderData => $order->with(['notes' => $notes]));
	}

	/**
	 * Send the buyer their receipt again.
	 *
	 * @return bool false when the store has no receipt mailer to send with
	 *
	 * @throws CommerceException no such order, or one nobody paid for
	 */
	public function resendReceipt(string $orderId): bool
	{
		return $this->receipts->resend($this->paid($orderId));
	}

	/**
	 * Cancel an order that is still waiting to be paid. A paid order is not
	 * cancelled, it is refunded.
	 *
	 * @throws CommerceException no such order, or one that is not waiting to be paid
	 */
	public function cancel(string $orderId): OrderData
	{
		return $this->orders->withLock($orderId, function (OrderData $order): OrderData {
			$transition = $this->machine->applyAdmin($order, AdminEvent::Cancel);
			if (!$transition->applied) {
				throw CommerceException::orderNotPending();
			}

			// A cancelled order's checkout at the provider is of no more use to anyone.
			return $order->withStatuses($transition->paymentStatus, $transition->fulfillmentStatus, $transition->status)->with(['providerClientSecret' => '']);
		});
	}

	/**
	 * Take the payment an order is held for review with: the order is paid,
	 * and its buyer gets everything a paid order gets. The money is already
	 * at the provider; this only says the store keeps it for this order.
	 *
	 * @throws CommerceException no such order, or one that is not held for review
	 */
	public function acceptPayment(string $orderId, Actor $by): OrderData
	{
		$actor      = $by->userId !== '' ? "user:{$by->authCollection}/{$by->userId}" : $by->kind->value;
		$transition = $this->payments->accept($orderId, $actor);
		// Asked twice, the second finds it done: that is not a refusal.
		if (!$transition->applied && $transition->reason !== PaymentEventProcessor::ALREADY_APPLIED) {
			throw CommerceException::orderNotInReview();
		}

		return $this->orders->find($orderId) ?? throw CommerceException::orderNotFound();
	}

	/**
	 * Tell the `order.paid` listeners again, after one of them failed to
	 * deliver. Listeners must cope with being told twice.
	 *
	 * @throws CommerceException no such order, or one nobody paid for
	 */
	public function refulfill(string $orderId): OrderData
	{
		$id = $this->paid($orderId)->id();

		// What the store delivers itself comes first: a download whose file
		// was added to the product after the order was paid.
		$this->orders->withLock($id, fn (OrderData $order): OrderData => $this->fulfillment->settle($this->downloads->issue($order)));
		// And a group the buyer could not be given the first time.
		$this->access->syncFor($this->orders->find($id) ?? throw CommerceException::orderNotFound());
		$this->fulfillment->announce($id);
		$this->fulfillment->redispatchPaid($id);

		return $this->orders->find($orderId) ?? throw CommerceException::orderNotFound();
	}

	/** @throws CommerceException */
	private function paid(string $orderId): OrderData
	{
		$order = $this->orders->find($orderId) ?? throw CommerceException::orderNotFound();

		return $order->holdsPayment() ? $order : throw CommerceException::orderNotPaid();
	}

	/** An http or https address with a host: what a browser will follow, and nothing a link could run. */
	private function isWebAddress(string $url): bool
	{
		$parts = parse_url($url);

		return is_array($parts)
			&& in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
			&& ($parts['host'] ?? '') !== ''
			&& filter_var($url, FILTER_VALIDATE_URL) !== false;
	}
}
