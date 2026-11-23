<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/** No tax is worked out: prices are what is charged. */
final readonly class NoTaxCalculator implements TaxCalculator
{
	public function mode(): string
	{
		return self::NONE;
	}

	public function calculate(array $lines, int $shipping, ?Address $address, string $currency): array
	{
		return ['lines' => array_fill_keys(array_keys($lines), 0), 'shipping' => 0, 'taxLines' => []];
	}
}
