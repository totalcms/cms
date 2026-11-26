<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The order-building listeners extensions have registered, and the one
 * place they are run.
 *
 * A listener that throws stops the checkout. Core events never do that, but
 * this is not an event: a marketplace order that lost the line saying who
 * the seller is would be taken, paid, and then paid out to nobody. A lost
 * sale is the smaller harm.
 */
final class OrderBuildingRegistry
{
	/** The most metadata one order may carry, JSON-encoded: order and lines together. */
	public const MAX_BYTES = 4096;

	/** @var list<array{listener: callable(OrderDraftMetadata, OrderDraft): void, priority: int, order: int}> */
	private array $listeners = [];

	private readonly LoggerInterface $logger;

	public function __construct(LoggerFactory $loggerFactory)
	{
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * @param callable(OrderDraftMetadata, OrderDraft): void $listener
	 * @param int                                            $priority lower runs first
	 */
	public function add(callable $listener, int $priority = 0): void
	{
		$this->listeners[] = ['listener' => $listener, 'priority' => $priority, 'order' => count($this->listeners)];
	}

	/**
	 * Run every listener and give back the draft with what they wrote. Each
	 * one sees what the ones before it wrote.
	 *
	 * @throws CommerceException when a listener throws, or the metadata is too large to store
	 */
	public function run(OrderDraft $draft): OrderDraft
	{
		$entries = $this->listeners;
		usort($entries, static fn (array $a, array $b): int => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);

		foreach ($entries as $entry) {
			$lines = array_filter(array_map(static fn (OrderLineDraft $line): array => $line->metadata, $draft->lines));
			$meta  = new OrderDraftMetadata(array_keys($draft->lines), $draft->metadata, $lines);

			try {
				($entry['listener'])($meta, $draft);
			} catch (\Throwable $e) {
				$this->logger->error('An order-building listener failed; the checkout was stopped', ['exception' => $e]);

				throw CommerceException::orderBuildFailed();
			}

			$draft = $draft->withMetadata($meta->order(), $meta->lines());
		}

		$encoded = json_encode([$draft->metadata, array_map(static fn (OrderLineDraft $line): array => $line->metadata, $draft->lines)]);
		if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
			$this->logger->error('Order-building listeners wrote more metadata than an order can carry', ['bytes' => $encoded === false ? null : strlen($encoded), 'limit' => self::MAX_BYTES]);

			throw CommerceException::metadataTooLarge();
		}

		return $draft;
	}
}
