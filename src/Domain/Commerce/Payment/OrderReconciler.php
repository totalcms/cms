<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Asks the provider about orders that have waited to be paid for a while,
 * and records what it answers.
 *
 * A store learns of a payment from the provider's webhook. When that never
 * arrives (a wrong address, a firewall, a server that was down) the buyer
 * has paid and the order still says it is waiting. The question is put from
 * this server with the store's secret key, and the answer goes through
 * {@see PaymentEventProcessor} like any webhook's event, so an order is
 * still paid in one place only, and once.
 */
final readonly class OrderReconciler
{
	private const PENDING = 'pending';

	public const PAID    = 'paid';
	public const EXPIRED = 'expired';
	public const REVIEW  = 'review';
	public const WAITING = 'waiting';
	public const ERROR   = 'error';

	private LoggerInterface $logger;

	public function __construct(
		private OrderRepository $orders,
		private PaymentProviderRegistry $providers,
		private PaymentEventProcessor $processor,
		private CommerceSettings $settings,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * @param int  $olderThanMinutes leave alone an order started less than this long ago: its webhook may be on its way
	 * @param bool $dryRun           ask, and say what was found, but change nothing
	 *
	 * @return list<array{number: int, id: string, result: string}> one row for each order looked at, oldest first
	 */
	public function run(int $olderThanMinutes = 10, bool $dryRun = false): array
	{
		$cutoff = time() - max(0, $olderThanMinutes) * 60;
		$rows   = array_filter($this->orders->index(), static fn (array $row): bool => ($row['paymentStatus'] ?? '') === self::PENDING);

		$report = [];
		foreach ($rows as $row) {
			// The index finds the candidates; the order's own file is the answer.
			$order = $this->orders->find((string)$row['id']);
			if (!$order instanceof OrderData || $order->paymentStatus() !== self::PENDING || $this->startedAt($order) > $cutoff) {
				continue;
			}

			$report[] = ['number' => $order->number(), 'id' => $order->id(), 'result' => $this->reconcile($order, $dryRun)];
		}
		usort($report, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

		return $report;
	}

	private function reconcile(OrderData $order, bool $dryRun): string
	{
		try {
			$event = $this->ask($order);
			if (!$event instanceof PaymentEvent) {
				return self::WAITING;
			}
			if ($dryRun) {
				return $event->type === PaymentEventType::Paid ? self::PAID : self::EXPIRED;
			}

			$this->processor->process($event, 'reconcile:' . $order->provider());
		} catch (\Throwable $e) {
			// One order that cannot be checked must not stop the rest.
			$this->logger->error('An order waiting to be paid could not be checked with its provider', ['orderId' => $order->id(), 'number' => $order->number(), 'error' => $e::class]);

			return self::ERROR;
		}

		return match ($this->orders->find($order->id())?->paymentStatus()) {
			'paid'    => self::PAID,
			'expired' => self::EXPIRED,
			'review'  => self::REVIEW,
			default   => self::WAITING,
		};
	}

	/**
	 * What became of the order's checkout: paid, run out, or null while
	 * there is nothing to record.
	 */
	private function ask(OrderData $order): ?PaymentEvent
	{
		if ($order->providerSessionRef() === '') {
			// The request that started it died before the provider's answer was
			// saved. Nobody was sent anywhere to pay, so nothing can pay it: it
			// is given up on once the store stops keeping a pending order.
			return $this->startedAt($order) <= time() - $this->settings->pendingOrderTtlHours() * 3600
				? new PaymentEvent($order->provider(), 'reconcile-' . $order->id() . '-expired', PaymentEventType::Expired, $order->id(), $order->grandTotalMoney(), '')
				: null;
		}

		$event = $this->providers->get($order->provider())?->fetchPayment($order->providerSessionRef());

		// Only what settles an order that is waiting. Anything later (a refund,
		// a dispute) has an event of its own.
		if (!$event instanceof PaymentEvent || !in_array($event->type, [PaymentEventType::Paid, PaymentEventType::Expired], true)) {
			return null;
		}

		// The provider was asked about this order's own checkout, so the
		// answer is about this order, whatever order it names or fails to.
		return new PaymentEvent($event->provider, $event->eventId, $event->type, $order->id(), $event->amount, $event->providerRef, $event->providerPaymentRef, $event->summary, $event->subscriptionRef, $event->customerRef, $event->tax, $event->orderTotal);
	}

	private function startedAt(OrderData $order): int
	{
		$started = $order->created() !== '' ? strtotime($order->created()) : false;

		// An order with no date is not skipped for being new.
		return $started === false ? 0 : $started;
	}
}
