<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Discount;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\FileLock;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Where discount codes are kept. A code's id is the code itself, in
 * lowercase, so checking one is a single read and never a search, and
 * nothing a buyer types reaches a file path unless it is a well-formed code.
 */
final readonly class DiscountRepository
{
	private const CODE = '/^[A-Za-z0-9-]{3,40}\z/';

	/** An order with one of these payment statuses used the code it carries. */
	private const USED = ['paid', 'partially_refunded'];

	private LoggerInterface $logger;

	public function __construct(
		private CommerceSettings $settings,
		private ObjectFetcher $objects,
		private ObjectUpdater $updater,
		private IndexReader $index,
		private FileLock $lock,
		private ActorContext $actors,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/** The id for a code as a buyer typed it, or null when it is not a code at all. */
	public function normalize(string $code): ?string
	{
		$code = trim($code);

		return preg_match(self::CODE, $code) === 1 ? strtolower($code) : null;
	}

	public function find(string $code): ?DiscountData
	{
		$id = $this->normalize($code);
		if ($id === null || !$this->objects->existsObject($this->collection(), $id)) {
			return null;
		}

		try {
			// From the file: a code's use count is written by another request the moment an order is paid.
			return DiscountData::fromObject($this->objects->fetchObjectFromDisk($this->collection(), $id)->toArray());
		} catch (\Throwable $e) {
			$this->logger->warning('A discount code could not be read, so it was treated as not there', ['code' => $id, 'error' => $e->getMessage()]);

			return null;
		}
	}

	/**
	 * Count one more use of a code, under a lock on that code, so two orders
	 * paid at the same moment both count. Nothing happens for a code that is
	 * not there.
	 */
	public function recordUse(string $code): void
	{
		$id         = $this->normalize($code);
		$collection = $this->collection();
		if ($id === null || !$this->objects->existsObject($collection, $id)) {
			return;
		}

		$this->lock->withLock("{$collection}/{$id}", function () use ($collection, $id): void {
			$stored              = $this->objects->fetchObjectFromDisk($collection, $id)->toArray();
			$used                = $stored['usedCount'] ?? 0;
			$stored['usedCount'] = (is_numeric($used) ? (int)$used : 0) + 1;

			$this->actors->runAs(Actor::system(), fn () => $this->updater->updateObject($collection, $id, $stored));
		});
	}

	/** How many paid orders this email has already used the code on. */
	public function usesByEmail(string $code, string $email): int
	{
		$id    = $this->normalize($code);
		$email = strtolower(trim($email));
		if ($id === null || $email === '') {
			return 0;
		}

		$uses = 0;
		foreach ($this->index->fetchIndex($this->settings->collection('orders'))->objects as $row) {
			$uses += is_string($row['discountCode'] ?? null) && strtolower($row['discountCode']) === $id
				&& is_string($row['email'] ?? null) && strtolower(trim($row['email'])) === $email
				&& in_array($row['paymentStatus'] ?? '', self::USED, true) ? 1 : 0;
		}

		return $uses;
	}

	private function collection(): string
	{
		return $this->settings->collection('discounts');
	}
}
