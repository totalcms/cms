<?php

declare(strict_types=1);

use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Money\Rounding;

// Money is an integer number of minor units (cents) in one currency. No float
// touches it: a rate or a divisor arrives as a decimal string, and every
// rounding is named.

it('holds an amount in minor units and an upper-case currency', function (): void {
	$money = Money::of(1999, 'usd');

	expect($money->amount())->toBe(1999)
		->and($money->currency())->toBe('USD')
		->and(Money::zero('EUR')->amount())->toBe(0)
		->and(Money::zero('EUR')->isZero())->toBeTrue()
		->and($money->isZero())->toBeFalse()
		->and(Money::of(-1, 'USD')->isNegative())->toBeTrue()
		->and($money->isNegative())->toBeFalse();
});

it('refuses a currency that is not three letters', function (string $currency): void {
	Money::of(1, $currency);
})->with(['US', 'USDX', 'U$D', '', '123'])->throws(InvalidArgumentException::class);

it('adds, subtracts and multiplies exactly', function (): void {
	$a = Money::of(1999, 'USD');

	expect($a->add(Money::of(1, 'USD'))->amount())->toBe(2000)
		->and($a->subtract(Money::of(2000, 'USD'))->amount())->toBe(-1)
		->and($a->multiply(3)->amount())->toBe(5997)
		->and($a->multiply(0)->isZero())->toBeTrue()
		->and($a->multiply(-1)->amount())->toBe(-1999);
});

it('refuses to add different currencies', fn () => Money::of(1, 'USD')->add(Money::of(1, 'EUR')))->throws(InvalidArgumentException::class);

it('refuses to subtract different currencies', fn () => Money::of(1, 'USD')->subtract(Money::of(1, 'EUR')))->throws(InvalidArgumentException::class);

it('rounds a percentage of a decimal-string rate without floats', function (int $minor, string $rate, Rounding $mode, int $out): void {
	expect(Money::of($minor, 'USD')->percentage($rate, $mode)->amount())->toBe($out);
})->with([
	[1000, '8.875', Rounding::HalfUp, 89], [1000, '8.85', Rounding::HalfUp, 89], [1000, '8.85', Rounding::HalfEven, 88],
	[1000, '8.85', Rounding::Down, 88], [1000, '8.81', Rounding::Up, 89], [0, '20', Rounding::HalfUp, 0],
	// 8.75 is exactly half: even goes to the even neighbor, up and down do what they say.
	[100, '8.5', Rounding::HalfEven, 8], [100, '9.5', Rounding::HalfEven, 10], [100, '8.5', Rounding::HalfUp, 9],
	[1999, '0', Rounding::HalfUp, 0], [1999, '100', Rounding::HalfUp, 1999], [200, '12.5', Rounding::Down, 25],
]);

it('rounds half-up by default', fn () => expect(Money::of(1000, 'USD')->percentage('8.85')->amount())->toBe(89));

it('rounds a negative amount the same distance from zero', function (): void {
	// A refund's tax is the charge's tax with the sign turned.
	expect(Money::of(-1000, 'USD')->percentage('8.85')->amount())->toBe(-89)
		->and(Money::of(-1000, 'USD')->percentage('8.85', Rounding::Down)->amount())->toBe(-88)
		->and(Money::of(-1000, 'USD')->percentage('8.81', Rounding::Up)->amount())->toBe(-89);
});

it('divides for inclusive tax', function (): void {
	expect(Money::of(10888, 'USD')->divide('1.08875')->amount())->toBe(10000)
		->and(Money::of(100, 'USD')->divide('3')->amount())->toBe(33)
		->and(Money::of(200, 'USD')->divide('3')->amount())->toBe(67)
		->and(Money::of(200, 'USD')->divide('3', Rounding::Down)->amount())->toBe(66)
		->and(Money::of(100, 'USD')->divide('3', Rounding::Up)->amount())->toBe(34);
});

it('refuses to divide by zero', fn () => Money::of(100, 'USD')->divide('0.0'))->throws(InvalidArgumentException::class);

it('rejects a malformed rate', function (string $rate): void {
	Money::of(100, 'USD')->percentage($rate);
})->with(['8,5', '', 'abc', '1e3', '-5', '.5', '5.', ' 5'])->throws(InvalidArgumentException::class);

it('knows currency exponents', fn () => expect([Money::exponent('JPY'), Money::exponent('BHD'), Money::exponent('usd'), Money::exponent('XYZ')])->toBe([0, 3, 2, 2]));

it('prints a decimal string by exponent', function (): void {
	expect([Money::of(1999, 'USD')->toDecimal(), Money::of(1500, 'JPY')->toDecimal(), Money::of(1235, 'BHD')->toDecimal()])->toBe(['19.99', '1500', '1.235'])
		->and(Money::of(5, 'USD')->toDecimal())->toBe('0.05')
		->and(Money::of(0, 'USD')->toDecimal())->toBe('0.00')
		->and(Money::of(-1999, 'USD')->toDecimal())->toBe('-19.99')
		->and(Money::of(-5, 'USD')->toDecimal())->toBe('-0.05');
});

it('compares by amount and currency', function (): void {
	expect(Money::of(100, 'USD')->equals(Money::of(100, 'usd')))->toBeTrue()
		->and(Money::of(100, 'USD')->equals(Money::of(101, 'USD')))->toBeFalse()
		->and(Money::of(100, 'USD')->equals(Money::of(100, 'EUR')))->toBeFalse();
});

it('throws on overflow instead of wrapping', function (Closure $sum): void {
	$sum();
})->with([
	'multiply'   => [fn () => Money::of(PHP_INT_MAX, 'USD')->multiply(2)],
	'add'        => [fn () => Money::of(PHP_INT_MAX, 'USD')->add(Money::of(1, 'USD'))],
	'subtract'   => [fn () => Money::of(PHP_INT_MIN, 'USD')->subtract(Money::of(1, 'USD'))],
	'percentage' => [fn () => Money::of(PHP_INT_MAX, 'USD')->percentage('99.999')],
])->throws(OverflowException::class);

it('is immutable', function (): void {
	$a = Money::of(100, 'USD');
	$b = $a->add(Money::of(50, 'USD'));
	$a->multiply(4);
	$a->percentage('50');

	expect($a->amount())->toBe(100)->and($b->amount())->toBe(150)->and($b)->not->toBe($a);
});
