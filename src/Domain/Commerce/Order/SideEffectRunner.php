<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Payment\PaymentEvent;

/**
 * What else must happen once when an order is paid, added by other parts of
 * the store: counting a discount code's use, starting a subscription,
 * granting an access group.
 *
 * Each runs after the order's downloads are issued and before its receipt is
 * queued, in the order it was registered, so the receipt and the `order.paid`
 * listeners see all of it done. Each is guarded by a flag on the order, so a
 * retry never runs one twice.
 */
final class SideEffectRunner
{
	/** @var array<string,callable(OrderData, PaymentEvent): ?OrderData> flag => what to run */
	private array $effects = [];

	/**
	 * @param string                                       $flag the name the order records it under once it has run
	 * @param callable(OrderData, PaymentEvent): ?OrderData $run  called with the order locked. Return the order
	 *                                                           to save, or null for no change. It must not lock
	 *                                                           the same order again; throwing leaves the flag
	 *                                                           unset, and the provider's retry runs it again
	 */
	public function register(string $flag, callable $run): void
	{
		if ($flag === '' || isset($this->effects[$flag])) {
			throw new \LogicException("A paid-order effect needs a flag of its own; '{$flag}' cannot be used.");
		}

		$this->effects[$flag] = $run;
	}

	/** @var list<callable(string, PaymentEvent): void> */
	private array $after = [];

	/**
	 * Something to do after every payment event for an order has been dealt
	 * with, whatever it was and whether or not it changed anything. It runs
	 * with no lock held, so it may read and change the order, and may tell
	 * listeners. It must cope with being run again for the same event.
	 *
	 * @param callable(string, PaymentEvent): void $run given the order's id and the event
	 */
	public function after(callable $run): void
	{
		$this->after[] = $run;
	}

	public function finish(string $orderId, PaymentEvent $event): void
	{
		foreach ($this->after as $run) {
			$run($orderId, $event);
		}
	}

	/** @return list<string> the flags, in the order their effects run */
	public function flags(): array
	{
		return array_keys($this->effects);
	}

	public function run(string $flag, OrderData $order, PaymentEvent $event): ?OrderData
	{
		return isset($this->effects[$flag]) ? ($this->effects[$flag])($order, $event) : null;
	}
}
