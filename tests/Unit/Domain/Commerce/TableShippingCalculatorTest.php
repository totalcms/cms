<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\TableShippingCalculator;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Support\Config;

/*
 * What an order costs to ship, from Settings → Commerce: nothing, one flat
 * rate, or a rate by where it is going. An order at or over a "free over"
 * amount ships free. Amounts are typed as prices ("5", "7.50") and worked
 * with as whole cents.
 */

/** A calculator for a store with these shipping settings. */
function shippingTable(array $settings): TableShippingCalculator
{
	$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->commerce = $settings;

	return new TableShippingCalculator(new CommerceSettings($config), new MoneyConverter());
}

/** The rates for an order of $subtotal cents going to $country (and $region). */
function shippingFor(array $settings, int $subtotal = 2000, ?string $country = 'US', string $region = ''): array
{
	$rates = shippingTable($settings)->rates([], Money::of($subtotal, 'USD'), $country === null ? null : new Address(country: $country, region: $region));

	return array_map(static fn ($rate): array => [$rate->id, $rate->label, $rate->amount->amount()], $rates);
}

const SHIPPING_ZONES = [
	['id' => 'zone_west', 'label' => 'West Coast', 'countries' => ['US'], 'regions' => ['CA', 'Oregon'], 'rate' => 4, 'freeOver' => 0],
	['id' => 'zone_us', 'label' => 'United States', 'countries' => ['US'], 'regions' => [], 'rate' => 7.5, 'freeOver' => 50],
	['id' => 'zone_eu', 'label' => 'Europe', 'countries' => ['DE', 'FR'], 'regions' => [], 'rate' => '18', 'freeOver' => ''],
];

describe('a store that does not charge for shipping', function (): void {
	it('ships for nothing', function (array $settings): void {
		expect(shippingFor($settings))->toBe([['none', 'Shipping', 0]]);
	})->with(['set to none' => [['shippingMode' => 'none']], 'never set' => [[]]]);

	it('ships anywhere, with or without an address', function (): void {
		expect(shippingFor(['shippingMode' => 'none'], 2000, null))->toHaveCount(1);
	});
});

describe('a flat rate', function (): void {
	it('is the same for every order', function (): void {
		expect(shippingFor(['shippingMode' => 'flat', 'shippingFlatRate' => 5]))->toBe([['flat', 'Shipping', 500]])
			->and(shippingFor(['shippingMode' => 'flat', 'shippingFlatRate' => '7.50'], 2000, 'DE'))->toBe([['flat', 'Shipping', 750]]);
	});

	it('is nothing for an order at or over the free amount, and says so', function (int $subtotal, int $charged, string $label): void {
		expect(shippingFor(['shippingMode' => 'flat', 'shippingFlatRate' => 5, 'shippingFreeOver' => 50], $subtotal))->toBe([['flat', $label, $charged]]);
	})->with([
		'just under' => [4999, 500, 'Shipping'],
		'exactly'    => [5000, 0, 'Free shipping'],
		'over'       => [9000, 0, 'Free shipping'],
	]);

	it('is never free when no free amount is set', function (): void {
		expect(shippingFor(['shippingMode' => 'flat', 'shippingFlatRate' => 5, 'shippingFreeOver' => 0], 999999))->toBe([['flat', 'Shipping', 500]]);
	});

	it('is nothing when no rate was typed', function (): void {
		expect(shippingFor(['shippingMode' => 'flat']))->toBe([['flat', 'Shipping', 0]]);
	});
});

describe('a rate by where the order is going', function (): void {
	it('takes the first zone the address is in', function (string $country, string $region, array $rate): void {
		expect(shippingFor(['shippingMode' => 'zones', 'shippingZones' => SHIPPING_ZONES], 2000, $country, $region))->toBe([$rate]);
	})->with([
		'a region of the first zone, by its code'     => ['US', 'CA', ['zone_west', 'West Coast', 400]],
		'the same region, by its name'                => ['US', 'california', ['zone_west', 'West Coast', 400]],
		'a region the zone names in full, by its code' => ['US', 'OR', ['zone_west', 'West Coast', 400]],
		'another region of that country'              => ['US', 'NY', ['zone_us', 'United States', 750]],
		'that country with no region given'           => ['US', '', ['zone_us', 'United States', 750]],
		'a country of a later zone'                   => ['FR', '', ['zone_eu', 'Europe', 1800]],
		'a country code in small letters'             => ['de', '', ['zone_eu', 'Europe', 1800]],
	]);

	it('does not ship to a place no zone covers', function (?string $country): void {
		expect(shippingFor(['shippingMode' => 'zones', 'shippingZones' => SHIPPING_ZONES], 2000, $country))->toBe([]);
	})->with(['another country' => ['JP'], 'no address yet' => [null], 'an address with no country' => ['']]);

	it('ships free at or over the zone\'s own free amount', function (int $subtotal, array $rate): void {
		expect(shippingFor(['shippingMode' => 'zones', 'shippingZones' => SHIPPING_ZONES], $subtotal, 'US', 'NY'))->toBe([$rate]);
	})->with([[4999, ['zone_us', 'United States', 750]], [5000, ['zone_us', 'Free shipping', 0]]]);

	it('covers everywhere with a zone that names no country', function (): void {
		$zones = [...SHIPPING_ZONES, ['id' => 'zone_rest', 'label' => 'Rest of the world', 'countries' => [], 'regions' => [], 'rate' => 30]];

		expect(shippingFor(['shippingMode' => 'zones', 'shippingZones' => $zones], 2000, 'JP'))->toBe([['zone_rest', 'Rest of the world', 3000]])
			// A named zone still comes first.
			->and(shippingFor(['shippingMode' => 'zones', 'shippingZones' => $zones], 2000, 'FR'))->toBe([['zone_eu', 'Europe', 1800]]);
	});

	it('calls a zone with no name "Shipping", and charges nothing for a rate it cannot read', function (): void {
		$zones = [['id' => 'z1', 'countries' => ['US'], 'rate' => 'lots']];

		expect(shippingFor(['shippingMode' => 'zones', 'shippingZones' => $zones]))->toBe([['z1', 'Shipping', 0]]);
	});

	it('has no rates when the store has set up no zones', function (): void {
		expect(shippingFor(['shippingMode' => 'zones']))->toBe([]);
	});
});

it('works in the order\'s own currency', function (): void {
	$rates = shippingTable(['shippingMode' => 'flat', 'shippingFlatRate' => 500])->rates([], Money::of(1000, 'JPY'), new Address(country: 'JP'));

	expect($rates[0]->amount->amount())->toBe(500)
		->and($rates[0]->amount->currency())->toBe('JPY');
});
