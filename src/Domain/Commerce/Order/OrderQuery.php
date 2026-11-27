<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * Finding orders for the admin: a page of them, filtered and searched, and
 * how many need someone.
 *
 * Everything is answered from the orders index, one read, so the list does
 * not open every order. Only the orders on the page shown are opened, and
 * the few that might have a download nobody delivered.
 */
final readonly class OrderQuery
{
	/** How long a paid order's download may go undelivered before the store is told, in seconds. */
	public const STALE_AFTER = 900;

	/** The reason given for an order whose download nobody delivered. It is worked out, never stored. */
	public const INCOMPLETE = 'fulfillment_incomplete';

	/**
	 * How many of the newest orders that might have a stuck download are
	 * opened to find out. A store with more undelivered orders than this has
	 * a problem the count already shows.
	 */
	private const STALE_CHECKED = 100;

	private const DIGITAL = 'digital';

	public function __construct(
		private OrderRepository $orders,
		private CommerceSettings $settings,
	) {
	}

	/**
	 * One page of orders, newest first.
	 *
	 * @param array<mixed> $filters `paymentStatus`, `fulfillmentStatus`, `needsAttention` (true for only
	 *                              those), `from` and `to` (Y-m-d, the day the order was placed), `q` (part
	 *                              of an order number or of an email). Anything else is ignored.
	 *
	 * @return array{orders: list<OrderData>, total: int}
	 */
	public function search(array $filters, int $page, int $perPage = 50): array
	{
		$rows = $this->orders->index();

		foreach (['paymentStatus', 'fulfillmentStatus'] as $field) {
			$value = $this->text($filters, $field);
			if ($value !== '') {
				$rows = array_filter($rows, static fn (array $row): bool => ($row[$field] ?? '') === $value);
			}
		}

		if (filter_var($filters['needsAttention'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
			$stale = array_flip($this->staleIds($rows));
			$rows  = array_filter($rows, static fn (array $row): bool => ($row['needsAttention'] ?? '') !== '' || isset($stale[$row['id'] ?? '']));
		}

		$from = $this->day($filters, 'from');
		$to   = $this->day($filters, 'to');
		if ($from !== '' || $to !== '') {
			$rows = array_filter($rows, function (array $row) use ($from, $to): bool {
				$placed = $this->dayOf($row['created'] ?? '');

				return $placed !== '' && ($from === '' || $placed >= $from) && ($to === '' || $placed <= $to);
			});
		}

		$q = $this->needle($this->text($filters, 'q'));
		if ($q !== '') {
			$rows = array_filter($rows, static fn (array $row): bool => str_contains((string)self::number($row), $q)
				|| (is_string($row['email'] ?? null) && str_contains(mb_strtolower($row['email']), $q)));
		}

		usort($rows, static fn (array $a, array $b): int => self::number($b) <=> self::number($a));

		$perPage = max(1, $perPage);
		$found   = [];
		foreach (array_slice($rows, (max(1, $page) - 1) * $perPage, $perPage) as $row) {
			$order = is_string($row['id'] ?? null) ? $this->orders->find($row['id']) : null;
			if ($order instanceof OrderData) {
				$found[] = $order;
			}
		}

		return ['orders' => $found, 'total' => count($rows)];
	}

	/**
	 * How many orders need someone: those marked with a reason (a payment
	 * that did not match, an oversold item, a failed refund, a dispute), and
	 * paid orders with a download nobody delivered in {@see STALE_AFTER}.
	 */
	public function attentionCount(): int
	{
		$rows    = $this->orders->index();
		$flagged = array_filter($rows, static fn (array $row): bool => ($row['needsAttention'] ?? '') !== '');
		$stale   = $this->staleIds(array_filter($rows, static fn (array $row): bool => ($row['needsAttention'] ?? '') === ''));

		return count($flagged) + count($stale);
	}

	/** Why an order needs someone, or '' when it does not. */
	public function attentionReason(OrderData $order): string
	{
		if ($order->needsAttention() !== '') {
			return $order->needsAttention();
		}

		return $this->isStale($order) ? self::INCOMPLETE : '';
	}

	/**
	 * The ids, among these index rows, of paid orders with a download that
	 * has gone undelivered too long. The index says which orders are paid,
	 * not yet delivered and old enough; only those are opened, to look at
	 * their lines. An order that is only waiting to be shipped is not one:
	 * that takes a person, and days.
	 *
	 * @param array<array<string,mixed>> $rows
	 *
	 * @return list<string>
	 */
	private function staleIds(array $rows): array
	{
		$cutoff     = time() - self::STALE_AFTER;
		$candidates = array_filter($rows, static fn (array $row): bool => ($row['paymentStatus'] ?? '') === OrderStateMachine::PAID
			&& in_array($row['fulfillmentStatus'] ?? '', [OrderStateMachine::UNFULFILLED, OrderStateMachine::PARTIAL], true)
			&& is_string($row['paidAt'] ?? null) && $row['paidAt'] !== '' && strtotime($row['paidAt']) <= $cutoff);
		usort($candidates, static fn (array $a, array $b): int => self::number($b) <=> self::number($a));

		$stale = [];
		foreach (array_slice($candidates, 0, self::STALE_CHECKED) as $row) {
			$order = is_string($row['id'] ?? null) ? $this->orders->find($row['id']) : null;
			if ($order instanceof OrderData && !$this->handled($order) && $this->hasUndeliveredDownload($order)) {
				$stale[] = $order->id();
			}
		}

		return $stale;
	}

	private function isStale(OrderData $order): bool
	{
		$paidAt = $order->paidAt() !== '' ? strtotime($order->paidAt()) : false;

		return $order->paymentStatus() === OrderStateMachine::PAID
			&& $paidAt !== false && $paidAt <= time() - self::STALE_AFTER
			&& !$this->handled($order)
			&& $this->hasUndeliveredDownload($order);
	}

	/** Whether staff have said the undelivered download is dealt with. Marked on the order, since nothing else records it. */
	private function handled(OrderData $order): bool
	{
		return ($order->attentionDismissed()['reason'] ?? '') === self::INCOMPLETE;
	}

	private function hasUndeliveredDownload(OrderData $order): bool
	{
		foreach ($order->lines() as $line) {
			if (($line['type'] ?? '') === self::DIGITAL && !in_array($line['fulfillmentStatus'] ?? '', [OrderStateMachine::FULFILLED, OrderStateMachine::NOT_REQUIRED], true)) {
				return true;
			}
		}

		return false;
	}

	/** What to look for: lower case, without the store's order number prefix. */
	private function needle(string $q): string
	{
		$q      = mb_strtolower(trim($q));
		$prefix = mb_strtolower($this->settings->orderNumberPrefix());
		if ($prefix !== '' && str_starts_with($q, $prefix) && ctype_digit(substr($q, strlen($prefix)))) {
			return substr($q, strlen($prefix));
		}

		return $q;
	}

	/** @param array<mixed> $filters */
	private function text(array $filters, string $key): string
	{
		$value = $filters[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}

	/**
	 * A filter's day as Y-m-d, or '' when it is not one.
	 *
	 * @param array<mixed> $filters
	 */
	private function day(array $filters, string $key): string
	{
		$value = $this->text($filters, $key);
		$date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

		return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
	}

	/** The day a stored time falls on, in the server's time zone. */
	private function dayOf(mixed $when): string
	{
		$time = is_string($when) && $when !== '' ? strtotime($when) : false;

		return $time === false ? '' : date('Y-m-d', $time);
	}

	/** @param array<string,mixed> $row */
	private static function number(array $row): int
	{
		return is_numeric($row['number'] ?? null) ? (int)$row['number'] : 0;
	}
}
