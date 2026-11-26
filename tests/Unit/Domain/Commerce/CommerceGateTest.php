<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\LicenseCommerceGate;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Support\Config;

// Commerce is sold on top of the Pro edition: a site needs both. A site that
// has both still chooses whether to use it, in Settings → Commerce.

/** The store's settings with Commerce switched on or off. */
function commerceSwitch(bool $on = true): CommerceSettings
{
	$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->commerce = ['enabled' => $on];

	return new CommerceSettings($config);
}

it('allows commerce only with Pro and the entitlement', function (bool $pro, bool $entitled, CommerceGateStatus $expected): void {
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('can')->with(EditionFeature::COMMERCE)->willReturn($pro);

	$gate = new LicenseCommerceGate($editions, entitlements($entitled ? ['totalcms/commerce'] : []), commerceSwitch());

	expect($gate->status())->toBe($expected)
		->and($gate->allowed())->toBe($expected === CommerceGateStatus::Allowed);
})->with([
	'Pro with the add-on'         => [true, true, CommerceGateStatus::Allowed],
	'Pro without the add-on'      => [true, false, CommerceGateStatus::NeedsEntitlement],
	// The edition comes first: there is no add-on to buy below Pro.
	'the add-on below Pro'        => [false, true, CommerceGateStatus::NeedsPro],
	'neither'                     => [false, false, CommerceGateStatus::NeedsPro],
]);

it('is open on a running trial and closed on an expired one', function (): void {
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturn(true);

	expect((new LicenseCommerceGate($editions, trialEntitlements(), commerceSwitch()))->status())->toBe(CommerceGateStatus::Allowed)
		->and((new LicenseCommerceGate($editions, trialEntitlements(running: false), commerceSwitch()))->status())->toBe(CommerceGateStatus::NeedsEntitlement);
});

it('is closed on a licensed site that has not switched Commerce on', function (): void {
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturn(true);

	$gate = new LicenseCommerceGate($editions, entitlements(['totalcms/commerce']), commerceSwitch(on: false));

	expect($gate->status())->toBe(CommerceGateStatus::Off)
		->and($gate->allowed())->toBeFalse()
		->and($gate->status()->refusal())->toBe('Commerce is switched off. Turn it on in Settings → Commerce.');
});

// What is missing is named in the order it has to be fixed: the license, then the switch.
it('names the license before the switch', function (bool $pro, CommerceGateStatus $expected): void {
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('can')->willReturn($pro);

	expect((new LicenseCommerceGate($editions, entitlements([]), commerceSwitch(on: false)))->status())->toBe($expected);
})->with([
	'below Pro'              => [false, CommerceGateStatus::NeedsPro],
	'Pro without the add-on' => [true, CommerceGateStatus::NeedsEntitlement],
]);

it('asks the license for the Commerce product by its id', function (): void {
	expect(LicenseCommerceGate::COMMERCE_PRODUCT)->toBe('totalcms/commerce');
});

it('maps COMMERCE to the Pro edition', function (): void {
	expect(EditionFeature::COMMERCE->requiredEdition())->toBe(Edition::PRO)
		->and(EditionFeature::COMMERCE->label())->toBe('Commerce')
		->and(EditionFeature::COMMERCE->value)->toBe('commerce');
});
