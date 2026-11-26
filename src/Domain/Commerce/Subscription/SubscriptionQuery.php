<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

/**
 * Finds subscriptions for the admin: a filtered, searched page of them,
 * and what the running ones add up to.
 *
 * It reads the subscriptions' index, so a list of thousands opens only the
 * page of them being shown.
 */
final readonly class SubscriptionQuery
{
	/** How many of each interval there are in a year. */
	private const PER_YEAR = ['day' => 365, 'week' => 52, 'month' => 12, 'year' => 1];

	public function __construct(private SubscriptionRepository $subscriptions)
	{
	}

	/**
	 * One page of subscriptions, newest first.
	 *
	 * @param array<mixed> $filters `status`, `needsAttention` (true for only those), `q` (part of an
	 *                              email). Anything else is ignored.
	 *
	 * @return array{subscriptions: list<SubscriptionData>, total: int}
	 */
	public function search(array $filters, int $page, int $perPage = 50): array
	{
		$rows = $this->subscriptions->index();

		$status = $this->text($filters, 'status');
		if ($status !== '') {
			$rows = array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === $status);
		}
		if (filter_var($filters['needsAttention'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
			$rows = array_filter($rows, static fn (array $row): bool => ($row['needsAttention'] ?? '') !== '');
		}
		$q = mb_strtolower($this->text($filters, 'q'));
		if ($q !== '') {
			$rows = array_filter($rows, static fn (array $row): bool => is_string($row['email'] ?? null) && str_contains(mb_strtolower($row['email']), $q));
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));

		$perPage = max(1, $perPage);
		$found   = [];
		foreach (array_slice($rows, (max(1, $page) - 1) * $perPage, $perPage) as $row) {
			$subscription = $this->subscriptions->find((string)$row['id']);
			if ($subscription instanceof SubscriptionData) {
				$found[] = $subscription;
			}
		}

		return ['subscriptions' => $found, 'total' => count($rows)];
	}

	/** How many subscriptions need someone: a payment that did not match, access that could not be given. */
	public function attentionCount(): int
	{
		return count(array_filter($this->subscriptions->index(), static fn (array $row): bool => ($row['needsAttention'] ?? '') !== ''));
	}

	/**
	 * What a subscription brings in a month, whatever it is billed by: a
	 * year's worth, divided by twelve, rounded half up. Minor units.
	 */
	public function monthlyValue(SubscriptionData $subscription): int
	{
		return self::monthly($subscription->recurringTotal(), $subscription->interval(), $subscription->intervalCount());
	}

	/**
	 * The subscriptions that are running, and what they bring in a month
	 * between them. In the currency of the first: a store sells in one.
	 *
	 * @return array{active: int, monthly: int, currency: string}
	 */
	public function running(): array
	{
		$active   = 0;
		$monthly  = 0;
		$currency = '';
		foreach ($this->subscriptions->index() as $row) {
			if (!in_array($row['status'] ?? '', SubscriptionData::LIVE, true)) {
				continue;
			}
			++$active;
			// A subscription's own record says how often it is billed; the index says the rest.
			$every    = $this->subscriptions->find((string)$row['id'])?->intervalCount() ?? 1;
			$monthly += self::monthly(is_numeric($row['recurringTotal'] ?? null) ? (int)$row['recurringTotal'] : 0, is_string($row['interval'] ?? null) ? $row['interval'] : '', $every);
			$currency = $currency !== '' ? $currency : (is_string($row['currency'] ?? null) ? $row['currency'] : '');
		}

		return ['active' => $active, 'monthly' => $monthly, 'currency' => $currency];
	}

	private static function monthly(int $total, string $interval, int $every): int
	{
		$perYear = self::PER_YEAR[$interval] ?? 0;

		return $perYear === 0 ? 0 : intdiv($total * $perYear * 2 + 12 * max(1, $every), 24 * max(1, $every));
	}

	/** @param array<mixed> $filters */
	private function text(array $filters, string $key): string
	{
		$value = $filters[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
