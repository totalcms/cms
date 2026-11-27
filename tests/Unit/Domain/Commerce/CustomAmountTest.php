<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Cart\CustomAmount;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Support\Config;

// The amount a buyer names for a product that lets them: a plain decimal,
// rounded to the minor unit, inside the product's and the store's limits.

beforeEach(function (): void {
	$this->settings = function (array $commerce = []): CommerceSettings {
		$config            = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->commerce  = $commerce;
		$config->dashboard = [];
		$config->domain    = 'shop.example';

		return new CommerceSettings($config);
	};
	$this->product = fn (float $min = 0.0): ProductView => ProductView::fromObject(['id' => 'give', 'title' => 'Give', 'price' => 10, 'customPrice' => true, 'minPrice' => $min]);
	$this->parse   = fn (string $raw, float $min = 0.0, bool $recurring = false, array $commerce = []): float => CustomAmount::parse($raw, ($this->product)($min), $recurring, ($this->settings)($commerce));
});

it('reads a plain decimal', function (string $raw, float $expected): void {
	expect(($this->parse)($raw))->toBe($expected);
})->with([['25', 25.0], ['10.99', 10.99], ['10.999', 11.0], ['10.994', 10.99], ['007', 7.0], [' 12 ', 12.0], ['0.50', 0.5], ['0', 0.0], ['0.00', 0.0]]);

it('refuses what is not a plain decimal', function (string $raw): void {
	expect(fn () => ($this->parse)($raw))->toThrow(CommerceException::class);
	try {
		($this->parse)($raw);
	} catch (CommerceException $e) {
		expect($e->errorCode)->toBe('amount_invalid');
	}
})->with(['.5', '5.', '+5', '-5', '5 0', '٥', '1e3', '10,50', 'abc', '', '0x10', '5.5.5', "5\n5", '12345678901234567890']);

it('refuses a number too long to be sane before reading it', function (): void {
	expect(fn () => ($this->parse)(str_repeat('9', 20)))->toThrow(CommerceException::class);
	expect(fn () => ($this->parse)(str_repeat('9', 400)))->toThrow(CommerceException::class);
});

it('keeps the floor of fifty minor units', function (): void {
	expect(fn () => ($this->parse)('0.49'))->toThrow(CommerceException::class)
		->and(($this->parse)('0.50'))->toBe(0.5);
});

it('rounds before it compares', function (): void {
	// 0.495 rounds up to 50 cents; 0.494 down to 49.
	expect(($this->parse)('0.495'))->toBe(0.5);
	expect(fn () => ($this->parse)('0.494'))->toThrow(CommerceException::class);
});

it('keeps to the product minimum, inclusive', function (): void {
	expect(($this->parse)('5', 5.0))->toBe(5.0)
		->and(fn () => ($this->parse)('4.99', 5.0))->toThrow(CommerceException::class);
});

it('keeps to the store maximum, inclusive', function (): void {
	expect(($this->parse)('10000'))->toBe(10000.0)
		->and(fn () => ($this->parse)('10000.01'))->toThrow(CommerceException::class)
		->and(($this->parse)('250', 0.0, false, ['customPriceMax' => 250]))->toBe(250.0)
		->and(fn () => ($this->parse)('250.01', 0.0, false, ['customPriceMax' => 250]))->toThrow(CommerceException::class);
});

it('allows nothing only for a one-time product with no minimum', function (): void {
	expect(($this->parse)('0'))->toBe(0.0)
		->and(fn () => ($this->parse)('0', 0.0, true))->toThrow(CommerceException::class)
		->and(fn () => ($this->parse)('0', 5.0))->toThrow(CommerceException::class);
});

it('names both limits in the refusal', function (): void {
	try {
		($this->parse)('1', 5.0);
	} catch (CommerceException $e) {
		expect($e->getMessage())->toContain('5.00')->and($e->getMessage())->toContain('10000.00');

		return;
	}

	throw new RuntimeException('Nothing was refused');
});

it('says the same without throwing', function (float $amount, float $min, bool $recurring, bool $allowed): void {
	expect(CustomAmount::allowed($amount, ($this->product)($min), $recurring, ($this->settings)()))->toBe($allowed);
})->with([
	[25.0, 0.0, false, true], [0.0, 0.0, false, true], [0.0, 0.0, true, false], [0.49, 0.0, false, false], [0.5, 0.0, false, true],
	[4.99, 5.0, false, false], [5.0, 5.0, false, true], [10000.0, 0.0, false, true], [10000.01, 0.0, false, false],
	[-1.0, 0.0, false, false], [NAN, 0.0, false, false], [INF, 0.0, false, false],
]);

it('states the real lower limit when the floor is above the minimum', function (): void {
	try {
		($this->parse)('0.10', 0.10);
	} catch (CommerceException $e) {
		expect($e->getMessage())->toBe('Enter an amount from 0.50 to 10000.00.');

		return;
	}

	throw new RuntimeException('Nothing was refused');
});

it('says the buyer may enter nothing when that is allowed', function (): void {
	try {
		($this->parse)('0.10');
	} catch (CommerceException $e) {
		expect($e->getMessage())->toBe('Enter 0, or an amount from 0.50 to 10000.00.');

		return;
	}

	throw new RuntimeException('Nothing was refused');
});

it('does not offer nothing for a plan', function (): void {
	try {
		($this->parse)('0.10', 0.0, true);
	} catch (CommerceException $e) {
		expect($e->getMessage())->toBe('Enter an amount from 0.50 to 10000.00.');

		return;
	}

	throw new RuntimeException('Nothing was refused');
});

it('writes the limits in the currency\'s own decimals', function (string $currency, string $expected): void {
	try {
		($this->parse)('1', 100.0, false, ['currency' => $currency, 'customPriceMax' => 250]);
	} catch (CommerceException $e) {
		expect($e->getMessage())->toBe($expected);

		return;
	}

	throw new RuntimeException('Nothing was refused');
})->with([
	['USD', 'Enter an amount from 100.00 to 250.00.'],
	['JPY', 'Enter an amount from 100 to 250.'],
	['KWD', 'Enter an amount from 100.000 to 250.000.'],
]);

it('treats a minimum that cannot be read as none and never throws', function (): void {
	// 0.00001 encodes in e-notation, which the converter refuses.
	expect(($this->parse)('3', 0.00001))->toBe(3.0)
		->and(CustomAmount::allowed(3.0, ($this->product)(0.00001), false, ($this->settings)()))->toBeTrue()
		->and(CustomAmount::allowed(0.0, ($this->product)(0.00001), false, ($this->settings)()))->toBeTrue();
});

it('falls back to the default ceiling when the maximum cannot be read', function (): void {
	$commerce = ['customPriceMax' => 0.00001];
	// customPriceMax() accepts any positive number, so this reaches the converter unread.
	expect(CustomAmount::allowed(10000.0, ($this->product)(), false, ($this->settings)($commerce)))->toBeTrue()
		->and(CustomAmount::allowed(10000.01, ($this->product)(), false, ($this->settings)($commerce)))->toBeFalse()
		->and(CustomAmount::allowed(1.0e15, ($this->product)(), false, ($this->settings)($commerce)))->toBeFalse();
});
