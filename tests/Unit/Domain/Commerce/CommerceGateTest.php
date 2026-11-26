<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\LicenseCommerceGate;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

// Commerce is sold on top of the Pro edition: a site needs both.

it('allows commerce only with Pro and the entitlement', function (bool $pro, bool $entitled, CommerceGateStatus $expected): void {
	$editions = $this->createMock(EditionFeatureService::class);
	$editions->method('can')->with(EditionFeature::COMMERCE)->willReturn($pro);

	$gate = new LicenseCommerceGate($editions, entitlements($entitled ? ['totalcms/commerce'] : []));

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

	expect((new LicenseCommerceGate($editions, trialEntitlements()))->status())->toBe(CommerceGateStatus::Allowed)
		->and((new LicenseCommerceGate($editions, trialEntitlements(running: false)))->status())->toBe(CommerceGateStatus::NeedsEntitlement);
});

it('asks the license for the Commerce product by its id', function (): void {
	expect(LicenseCommerceGate::COMMERCE_PRODUCT)->toBe('totalcms/commerce');
});

it('maps COMMERCE to the Pro edition', function (): void {
	expect(EditionFeature::COMMERCE->requiredEdition())->toBe(Edition::PRO)
		->and(EditionFeature::COMMERCE->label())->toBe('Commerce')
		->and(EditionFeature::COMMERCE->value)->toBe('commerce');
});
