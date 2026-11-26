<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\FileLock;

/**
 * Where orders are kept.
 *
 * An order is always read from its file, never from a cache: it is a record
 * of money, and a webhook in another process may have changed it a moment
 * ago. It is written as the store itself, and changed only under a lock on
 * that order ({@see withLock()}).
 */
final readonly class OrderRepository
{
	public function __construct(
		private CommerceSettings $settings,
		private ObjectFetcher $objects,
		private ObjectSaver $saver,
		private ObjectUpdater $updater,
		private IndexReader $index,
		private FileLock $lock,
		private ActorContext $actors,
		private OrderNumberer $numberer,
	) {
	}

	/**
	 * Store a draft as a new order: pending, unpaid, with its number and the
	 * secret that signs its links.
	 */
	public function create(OrderDraft $draft, string $provider, string $subscriptionId = ''): OrderData
	{
		$totals = $draft->totals;
		$lines  = [];
		foreach ($draft->lines as $lineId => $line) {
			$lines[$lineId] = $line->toArray() + ['fulfillmentStatus' => 'unfulfilled', 'fulfillmentNote' => '', 'downloads' => [], 'refundedQty' => 0];
		}

		$now  = date(DATE_ATOM);
		$data = [
			'id'                   => bin2hex(random_bytes(16)),
			'number'               => $this->numberer->next(),
			'kind'                 => OrderData::KIND_CHECKOUT,
			'status'               => 'open',
			'paymentStatus'        => 'pending',
			'fulfillmentStatus'    => 'unfulfilled',
			'needsAttention'       => '',
			'currency'             => $draft->currency,
			'email'                => $draft->email,
			'customerCollection'   => $draft->customerCollection,
			'customerId'           => $draft->customerId,
			'subscriptionId'       => $subscriptionId,
			'lines'                => $lines,
			'subtotal'             => $totals->subtotal,
			'discountTotal'        => $totals->discountTotal,
			'shippingTotal'        => $totals->shippingTotal,
			'taxTotal'             => $totals->taxTotal,
			'grandTotal'           => $totals->grandTotal,
			'refundedTotal'        => 0,
			'taxMode'              => $totals->taxMode,
			'taxLines'             => $totals->taxLines,
			'shippingLine'         => $totals->shippingLine ?? [],
			'discountCode'         => $totals->discountLine['code'] ?? '',
			'discountLine'         => $totals->discountLine ?? [],
			'shippingAddress'      => $draft->shippingAddress?->toArray() ?? [],
			'billingAddress'       => $draft->billingAddress?->toArray() ?? [],
			'provider'             => $provider,
			'providerSessionRef'   => '',
			'providerPaymentRef'   => '',
			'providerClientSecret' => '',
			'attributes'           => $draft->attributes,
			'metadata'             => $draft->metadata,
			'flags'                => [],
			'accessToken'          => bin2hex(random_bytes(32)),
			'created'              => $now,
			'updated'              => $now,
		];

		$orders = $this->collection();
		$this->actors->runAs(Actor::system(), fn (): ObjectData => $this->saver->saveObject($orders, $data));

		return $this->find($data['id']) ?? new OrderData($data);
	}

	/**
	 * The order for one period of a subscription. Its id is worked out from
	 * the provider's invoice, so the same invoice is always the same order.
	 *
	 * @param array<string,mixed> $fields what the subscription says of it: lines, totals, the subscriber
	 */
	public function createRenewal(string $id, array $fields): OrderData
	{
		$now  = date(DATE_ATOM);
		$data = $fields + [
			'id'                   => $id,
			'number'               => $this->numberer->next(),
			'kind'                 => OrderData::KIND_RENEWAL,
			'status'               => 'open',
			'paymentStatus'        => 'pending',
			'fulfillmentStatus'    => 'unfulfilled',
			'needsAttention'       => '',
			'refundedTotal'        => 0,
			'shippingTotal'        => 0,
			'shippingLine'         => [],
			'shippingAddress'      => [],
			'discountLine'         => [],
			'providerPaymentRef'   => '',
			'providerClientSecret' => '',
			'flags'                => [],
			'accessToken'          => bin2hex(random_bytes(32)),
			'created'              => $now,
			'updated'              => $now,
		];

		$orders = $this->collection();
		$this->actors->runAs(Actor::system(), fn (): ObjectData => $this->saver->saveObject($orders, $data));

		return $this->find($id) ?? new OrderData($data);
	}

	public function exists(string $id): bool
	{
		return $id !== '' && $this->objects->existsObject($this->collection(), $id);
	}

	public function find(string $id): ?OrderData
	{
		return $this->exists($id) ? new OrderData($this->objects->fetchObjectFromDisk($this->collection(), $id)->toArray()) : null;
	}

	/**
	 * Change an order. $fn is given the order as it is on disk at that
	 * moment and returns the order to save; nothing else can change it
	 * until $fn is done.
	 *
	 * @param callable(OrderData): OrderData $fn
	 *
	 * @throws CommerceException when there is no such order
	 */
	public function withLock(string $id, callable $fn): OrderData
	{
		if (!$this->exists($id)) {
			throw CommerceException::orderNotFound();
		}

		return $this->lock->withLock($this->collection() . '/' . $id, function () use ($id, $fn): OrderData {
			$order   = $this->find($id) ?? throw CommerceException::orderNotFound();
			$changed = $fn($order);

			// Nothing to write when nothing changed: a save rebuilds the index and tells every listener.
			return $changed->toArray() === $order->toArray() ? $order : $this->save($changed);
		});
	}

	/**
	 * Write an order. Only for a caller already inside {@see withLock()} for
	 * it: outside the lock, this can undo what another process just wrote.
	 */
	public function save(OrderData $order): OrderData
	{
		$orders = $this->collection();
		$data   = ['updated' => date(DATE_ATOM)] + $order->toArray();
		$this->actors->runAs(Actor::system(), fn (): ObjectData => $this->updater->updateObject($orders, $order->id(), $data));

		return new OrderData($data);
	}

	/**
	 * The orders a cart started that are still waiting to be paid.
	 *
	 * @return list<OrderData>
	 */
	public function pendingForCart(string $cartKey): array
	{
		if ($cartKey === '') {
			return [];
		}

		$found = [];
		foreach ($this->idsWhere(static fn (array $row): bool => ($row['paymentStatus'] ?? '') === 'pending') as $id) {
			$order = $this->find($id);
			// The index is a way to find candidates; the file is the answer.
			if ($order instanceof OrderData && $order->paymentStatus() === 'pending' && $order->cartKey() === $cartKey) {
				$found[] = $order;
			}
		}

		return $found;
	}

	/**
	 * A customer's orders, newest first.
	 *
	 * @return list<OrderData>
	 */
	public function forCustomer(string $collection, string $customerId, int $limit = 20): array
	{
		if ($customerId === '') {
			return [];
		}

		$found = [];
		foreach ($this->idsWhere(static fn (array $row): bool => ($row['customerId'] ?? '') === $customerId) as $id) {
			$order = $this->find($id);
			// The index does not hold the collection, and two auth collections may share a user id.
			if ($order instanceof OrderData && $order->customerId() === $customerId && $order->customerCollection() === $collection) {
				$found[] = $order;
			}
		}
		usort($found, static fn (OrderData $a, OrderData $b): int => $b->number() <=> $a->number());

		return array_slice($found, 0, max(1, $limit));
	}

	/**
	 * The order a payment was for, when the provider's event names the
	 * payment and not the order (a refund or dispute of a renewal).
	 */
	public function findByPaymentRef(string $provider, string $providerPaymentRef): ?OrderData
	{
		if ($providerPaymentRef === '') {
			return null;
		}

		foreach ($this->idsWhere(static fn (array $row): bool => ($row['providerPaymentRef'] ?? '') === $providerPaymentRef) as $id) {
			$order = $this->find($id);
			if ($order instanceof OrderData && $order->provider() === $provider && $order->providerPaymentRef() === $providerPaymentRef) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * @param \Closure(array<string,mixed>): bool $matches
	 *
	 * @return list<string>
	 */
	private function idsWhere(\Closure $matches): array
	{
		$ids = [];
		foreach ($this->index->fetchIndex($this->collection())->objects as $row) {
			if (is_string($row['id'] ?? null) && $matches($row)) {
				$ids[] = $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * What the index holds about every order: its number, email, statuses,
	 * total and dates. One read, however many orders there are.
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

	private function collection(): string
	{
		return $this->settings->collection('orders');
	}
}
