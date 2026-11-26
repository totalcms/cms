<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Service\MoneyFormatter;
use TotalCMS\Domain\Money\Money;

it('formats USD with intl when available', fn () => expect((new MoneyFormatter())->format(Money::of(1999, 'USD'), 'en_US'))->toBe('$19.99'));
it('formats JPY without decimals', fn () => expect((new MoneyFormatter())->format(Money::of(1500, 'JPY'), 'en_US'))->toBe('¥1,500'));

it('formats for the locale it is given', function (): void {
	// intl separates the number and the symbol with a no-break space.
	expect(str_replace("\u{00A0}", ' ', (new MoneyFormatter())->format(Money::of(123456, 'EUR'), 'de_DE')))->toBe('1.234,56 €');
});

it('uses US English when no locale is given', fn () => expect((new MoneyFormatter())->format(Money::of(500000, 'USD')))->toBe('$5,000.00'));
it('formats three-decimal currencies', fn () => expect((new MoneyFormatter())->format(Money::of(1235, 'BHD'), 'en_US'))->toContain('1.235'));
it('formats a negative amount, as a refund is', fn () => expect((new MoneyFormatter())->format(Money::of(-500, 'USD'), 'en_US'))->toBe('-$5.00'));

describe('without the intl extension', function (): void {
	it('writes the symbol and the number', function (int $minor, string $currency, string $out): void {
		expect((new MoneyFormatter(useIntl: false))->format(Money::of($minor, $currency), 'en_US'))->toBe($out);
	})->with([
		[1999, 'USD', '$19.99'], [500000, 'USD', '$5,000.00'], [1500, 'JPY', '¥1,500'], [123456, 'EUR', '€1,234.56'], [999, 'GBP', '£9.99'],
		[-500, 'USD', '-$5.00'], [1235, 'BHD', 'BHD 1.235'], [1000, 'CHF', 'CHF 10.00'],
	]);
});
