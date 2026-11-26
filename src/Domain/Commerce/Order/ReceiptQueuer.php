<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

/**
 * Queues the receipt for a paid order. The email is sent by the job queue,
 * which sends one mailer to one object once, so a retried webhook cannot
 * send a second receipt.
 *
 * Not final and not readonly, so a test can put one in its place that fails.
 */
class ReceiptQueuer
{
	public function __construct(
		private readonly JobQueuer $jobs,
		private readonly CommerceSettings $settings,
	) {
	}

	public function queue(OrderData $order): void
	{
		$mailer = $this->settings->receiptMailer();
		if ($mailer === '') {
			return;
		}

		$this->jobs->queueEmail(['mailerId' => $mailer, 'collection' => $this->settings->collection('orders'), 'objectId' => $order->id()]);
	}

	/**
	 * Send the receipt again, because staff asked. Naming the recipient is
	 * what tells the job queue this one is wanted although the order has
	 * had its receipt: it goes to the order's own email, every time.
	 *
	 * @return bool false when the store has no receipt mailer to send with
	 */
	public function resend(OrderData $order): bool
	{
		$mailer = $this->settings->receiptMailer();
		if ($mailer === '' || $order->email() === '') {
			return false;
		}

		$this->jobs->queueEmail(['mailerId' => $mailer, 'collection' => $this->settings->collection('orders'), 'objectId' => $order->id(), 'overrideTo' => $order->email()]);

		return true;
	}
}
