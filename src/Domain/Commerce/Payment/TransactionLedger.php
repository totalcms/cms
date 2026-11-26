<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

/**
 * The store's record of money moving: one line for each thing a provider
 * said happened to a payment.
 *
 * It is only ever added to. There is no method here that changes or removes
 * a line, and a test holds that. Recording the same event twice writes one
 * line, so a provider's retry cannot count a payment twice.
 */
final readonly class TransactionLedger
{
	private const MAX_SUMMARY_KEYS = 20;

	public function __construct(
		private CommerceSettings $settings,
		private ObjectSaver $saver,
		private ObjectFetcher $objects,
		private IndexReader $index,
		private ActorContext $actors,
	) {
	}

	/**
	 * @param string              $type            checkout_created | payment | payment_failed | payment_accepted | refund | dispute | dispute_closed
	 * @param string              $status          succeeded | failed | pending
	 * @param Money               $amount          positive, whichever way the money went; the type says which
	 * @param string              $providerRef     the payment, refund or dispute id at the provider
	 * @param string              $actor           'webhook:{provider}', 'system', or 'user:{collection}/{id}' for staff
	 * @param array<string,mixed> $summary         a few plain facts about the event; never card data
	 *
	 * @return string the line's id
	 */
	public function record(OrderData $order, string $type, string $status, Money $amount, string $providerRef, string $providerEventId, string $actor, array $summary): string
	{
		$existing = $providerEventId === '' ? null : $this->existing($order->id(), $type, $providerEventId);
		if ($existing !== null) {
			return $existing;
		}

		$data = [
			'id'              => $this->newId(),
			'orderId'         => $order->id(),
			'provider'        => $order->provider(),
			'type'            => $type,
			'status'          => $status,
			'amount'          => $amount->amount(),
			'currency'        => $amount->currency(),
			'providerRef'     => $providerRef,
			'providerEventId' => $providerEventId,
			'actor'           => $actor,
			'summary'         => array_slice(array_filter($summary, is_scalar(...)), 0, self::MAX_SUMMARY_KEYS, true),
			'created'         => date(DATE_ATOM),
		];

		$collection = $this->collection();
		$this->actors->runAs(Actor::system(), fn (): ObjectData => $this->saver->saveObject($collection, $data));

		return $data['id'];
	}

	/**
	 * Every line for an order, oldest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function forOrder(string $orderId): array
	{
		$collection = $this->collection();
		$lines      = [];
		foreach ($this->index->fetchIndex($collection)->objects as $row) {
			if (($row['orderId'] ?? '') === $orderId && is_string($row['id'] ?? null) && $this->objects->existsObject($collection, $row['id'])) {
				$lines[] = $this->objects->fetchObjectFromDisk($collection, $row['id'])->toArray();
			}
		}
		usort($lines, static fn (array $a, array $b): int => (string)($a['id'] ?? '') <=> (string)($b['id'] ?? ''));

		return $lines;
	}

	/**
	 * 32 hex characters that sort in the order they were made: the time to
	 * the microsecond, then random. Two lines written in the same second
	 * still list in the order they happened.
	 */
	private function newId(): string
	{
		return sprintf('%014x', (int)(microtime(true) * 1_000_000)) . bin2hex(random_bytes(9));
	}

	private function existing(string $orderId, string $type, string $providerEventId): ?string
	{
		foreach ($this->index->fetchIndex($this->collection())->objects as $row) {
			if (($row['orderId'] ?? '') === $orderId && ($row['type'] ?? '') === $type && ($row['providerEventId'] ?? '') === $providerEventId && is_string($row['id'] ?? null)) {
				return $row['id'];
			}
		}

		return null;
	}

	private function collection(): string
	{
		return $this->settings->collection('transactions');
	}
}
