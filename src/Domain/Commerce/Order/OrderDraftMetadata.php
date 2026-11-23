<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Where an order-building listener writes. It holds metadata and nothing
 * else: there is no way to reach a price from here.
 */
final class OrderDraftMetadata
{
	/**
	 * @param list<string>                      $lineIds the order's line ids
	 * @param array<string,mixed>               $order
	 * @param array<string,array<string,mixed>> $lines
	 */
	public function __construct(
		private readonly array $lineIds,
		private array $order = [],
		private array $lines = [],
	) {
	}

	/** @param scalar|array<mixed> $value */
	public function setOrder(string $key, string|int|float|bool|array $value): void
	{
		if ($key !== '') {
			$this->order[$key] = $value;
		}
	}

	/**
	 * Nothing is written for a line the order does not have.
	 *
	 * @param scalar|array<mixed> $value
	 */
	public function setLine(string $lineId, string $key, string|int|float|bool|array $value): void
	{
		if ($key !== '' && in_array($lineId, $this->lineIds, true)) {
			$this->lines[$lineId][$key] = $value;
		}
	}

	/** @return array<string,mixed> */
	public function order(): array
	{
		return $this->order;
	}

	/** @return array<string,array<string,mixed>> */
	public function lines(): array
	{
		return $this->lines;
	}
}
