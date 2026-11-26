<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\FileLock;

/**
 * Where subscriptions are kept. Like an order, a subscription is written by
 * the store only, and changed only inside {@see withLock()}.
 */
final readonly class SubscriptionRepository
{
	public function __construct(
		private CommerceSettings $settings,
		private ObjectFetcher $objects,
		private ObjectSaver $saver,
		private ObjectUpdater $updater,
		private IndexReader $index,
		private FileLock $lock,
		private ActorContext $actors,
	) {
	}

	/**
	 * Start a subscription for an order that is about to be paid. It waits,
	 * `pending`, until that order's payment is confirmed.
	 */
	public function createPending(SubscriptionDraft $draft, OrderData $origin): SubscriptionData
	{
		$totals = $draft->totals;
		$now    = date(DATE_ATOM);
		$data   = [
			'id'                      => bin2hex(random_bytes(16)),
			'status'                  => SubscriptionData::PENDING,
			'email'                   => $origin->email(),
			'customerCollection'      => $origin->customerCollection(),
			'customerId'              => $origin->customerId(),
			'currency'                => $origin->currency(),
			'provider'                => $origin->provider(),
			'providerSubscriptionRef' => '',
			'providerCustomerRef'     => '',
			'originOrderId'           => $origin->id(),
			'lastOrderId'             => $origin->id(),
			'lines'                   => array_map(static fn (OrderLineDraft $line): array => $line->toArray(), $draft->lines),
			'interval'                => $draft->recurrence->interval,
			'intervalCount'           => $draft->recurrence->intervalCount,
			'recurringSubtotal'       => $totals->subtotal,
			'recurringDiscount'       => $totals->discountTotal,
			'recurringTax'            => $totals->taxTotal,
			'recurringTotal'          => $totals->grandTotal,
			'taxMode'                 => $totals->taxMode,
			'taxLines'                => $totals->taxLines,
			'billingAddress'          => $origin->billingAddress(),
			'discountCode'            => $draft->discountCode,
			'attributes'              => $origin->attributes(),
			'metadata'                => $origin->metadata(),
			'trialEndsAt'             => '',
			'currentPeriodEnd'        => '',
			'cancelAtPeriodEnd'       => false,
			'canceledAt'              => '',
			'renewalCount'            => 0,
			'needsAttention'          => '',
			'lastFailedInvoiceRef'    => '',
			'flags'                   => [],
			'accessToken'             => bin2hex(random_bytes(32)),
			'created'                 => $now,
			'updated'                 => $now,
		];

		$subscriptions = $this->collection();
		$this->actors->runAs(Actor::system(), fn (): ObjectData => $this->saver->saveObject($subscriptions, $data));

		return $this->find($data['id']) ?? new SubscriptionData($data);
	}

	public function exists(string $id): bool
	{
		return $id !== '' && $this->objects->existsObject($this->collection(), $id);
	}

	public function find(string $id): ?SubscriptionData
	{
		return $this->exists($id) ? new SubscriptionData($this->objects->fetchObjectFromDisk($this->collection(), $id)->toArray()) : null;
	}

	/** The subscription a provider knows by this reference. */
	public function findByProviderRef(string $provider, string $providerSubscriptionRef): ?SubscriptionData
	{
		if ($providerSubscriptionRef === '') {
			return null;
		}

		foreach ($this->idsWhere(static fn (array $row): bool => ($row['providerSubscriptionRef'] ?? '') === $providerSubscriptionRef) as $id) {
			$subscription = $this->find($id);
			// The index finds the candidates; the record is the answer.
			if ($subscription instanceof SubscriptionData && $subscription->provider() === $provider && $subscription->providerSubscriptionRef() === $providerSubscriptionRef) {
				return $subscription;
			}
		}

		return null;
	}

	/**
	 * Change a subscription. $fn is given it as it is on disk at that
	 * moment and returns the one to save; nothing else can change it until
	 * $fn is done.
	 *
	 * @param callable(SubscriptionData): SubscriptionData $fn
	 *
	 * @throws CommerceException when there is no such subscription
	 */
	public function withLock(string $id, callable $fn): SubscriptionData
	{
		if (!$this->exists($id)) {
			throw CommerceException::subscriptionNotFound();
		}

		return $this->lock->withLock($this->collection() . '/' . $id, function () use ($id, $fn): SubscriptionData {
			$subscription = $this->find($id) ?? throw CommerceException::subscriptionNotFound();
			$changed      = $fn($subscription);
			if ($changed->toArray() === $subscription->toArray()) {
				return $subscription;
			}

			$data = ['updated' => date(DATE_ATOM)] + $changed->toArray();
			$this->actors->runAs(Actor::system(), fn (): ObjectData => $this->updater->updateObject($this->collection(), $id, $data));

			return new SubscriptionData($data);
		});
	}

	/**
	 * A customer's subscriptions, newest first.
	 *
	 * @return list<SubscriptionData>
	 */
	public function forCustomer(string $collection, string $customerId): array
	{
		if ($customerId === '') {
			return [];
		}

		$found = [];
		foreach ($this->idsWhere(static fn (array $row): bool => ($row['customerId'] ?? '') === $customerId) as $id) {
			$subscription = $this->find($id);
			// The index does not hold the collection, and two auth collections may share a user id.
			if ($subscription instanceof SubscriptionData && $subscription->customerId() === $customerId && $subscription->customerCollection() === $collection) {
				$found[] = $subscription;
			}
		}
		usort($found, static fn (SubscriptionData $a, SubscriptionData $b): int => strcmp($b->created(), $a->created()));

		return $found;
	}

	/**
	 * The customer's subscriptions that give them what they pay for now.
	 *
	 * @return list<SubscriptionData>
	 */
	public function liveForCustomer(string $collection, string $customerId): array
	{
		return array_values(array_filter($this->forCustomer($collection, $customerId), static fn (SubscriptionData $subscription): bool => $subscription->isLive()));
	}

	/**
	 * What the index holds about every subscription. One read, however many there are.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function index(): array
	{
		$rows = [];
		foreach ($this->index->fetchIndex($this->collection())->objects as $row) {
			if (is_string($row['id'] ?? null)) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @param \Closure(array<string,mixed>): bool $matches
	 *
	 * @return list<string>
	 */
	private function idsWhere(\Closure $matches): array
	{
		$ids = [];
		foreach ($this->index() as $row) {
			if ($matches($row)) {
				$ids[] = (string)$row['id'];
			}
		}

		return $ids;
	}

	private function collection(): string
	{
		return $this->settings->collection('subscriptions');
	}
}
