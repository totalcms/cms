<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Service\MoneyConverter;

/*
 * The one place a price as a person typed it becomes whole minor units. It
 * never goes through float arithmetic, so 19.99 is 1999 on every PHP version.
 */

it('converts price field values to minor units without float drift', function (mixed $in, string $currency, int $out): void {
	expect((new MoneyConverter())->fromPrice($in, $currency)->amount())->toBe($out);
})->with([
	[19.99, 'USD', 1999], [0.29, 'USD', 29], [1.005, 'USD', 101], ['$5,000.00', 'USD', 500000],
	['100,50', 'EUR', 10050], [1500, 'JPY', 1500], [12.5, 'JPY', 13], [1.2345, 'BHD', 1235], [0, 'USD', 0],
	// The classic float traps: none of these is exact in binary.
	[0.1, 'USD', 10], [0.07, 'USD', 7], [8.2, 'USD', 820], [1.15, 'USD', 115], [4.35, 'USD', 435], [1.255, 'USD', 126], [2.675, 'USD', 268],
	[1.004, 'USD', 100], [0.994, 'USD', 99], [0.995, 'USD', 100], [999999.99, 'USD', 99999999],
	['19.99', 'USD', 1999], ['7', 'USD', 700], ['', 'USD', 0], ['1.234,56 €', 'EUR', 123456], [5.0, 'USD', 500],
]);

it('keeps the currency', function (): void {
	expect((new MoneyConverter())->fromPrice(5, 'GBP')->currency())->toBe('GBP');
});

it('rejects negative prices', fn () => (new MoneyConverter())->fromPrice(-1, 'USD'))->throws(InvalidArgumentException::class);
it('rejects a negative price in a string', fn () => (new MoneyConverter())->fromPrice('-0.01', 'USD'))->throws(InvalidArgumentException::class);
it('rejects a price that is not a number', fn () => (new MoneyConverter())->fromPrice(NAN, 'USD'))->throws(InvalidArgumentException::class);
it('rejects a price too large to write out', fn () => (new MoneyConverter())->fromPrice(1.0e30, 'USD'))->throws(InvalidArgumentException::class);
it('rejects an amount too large to hold exactly', fn () => (new MoneyConverter())->fromDecimal('99999999999999999999', 'USD'))->throws(OverflowException::class);

it('converts a plain decimal, such as a tax amount or a refund typed by staff', function (string $in, string $currency, int $out): void {
	expect((new MoneyConverter())->fromDecimal($in, $currency)->amount())->toBe($out);
})->with([
	['4.95', 'USD', 495], ['50', 'USD', 5000], ['0.005', 'USD', 1], ['0.0049', 'USD', 0], ['.5', 'USD', 50], ['5.', 'USD', 500], [' 12.00 ', 'USD', 1200],
]);

it('does not guess at a decimal that is not plain', function (string $in): void {
	expect(fn () => (new MoneyConverter())->fromDecimal($in, 'USD'))->toThrow(InvalidArgumentException::class);
})->with(['1,000.00', '$5', 'ten', '', '.', '1e3', '1.2.3']);
