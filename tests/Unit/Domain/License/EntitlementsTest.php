<?php

declare(strict_types=1);

use Tests\Fakes\StubLicenseValidator;
use TotalCMS\Domain\License\Service\Entitlements;
use TotalCMS\Domain\License\Service\LicenseEntitlementSource;

// Entitlements answers "may this site use that paid product", from the
// license and from any other source a later release adds.

it('is true when any source says true', function (): void {
	$e = new Entitlements([fakeSource([]), fakeSource(['acme/x'])], licenseSource([]), licenseStatus(dev: false));

	expect($e->has('acme/x'))->toBeTrue()
		->and($e->has('acme/y'))->toBeFalse();
});

it('has what the license carries', function (): void {
	expect(entitlements(['totalcms/commerce'])->has('totalcms/commerce'))->toBeTrue()
		->and(entitlements(['totalcms/commerce'])->has('totalcms/s3-uploads'))->toBeFalse()
		->and(entitlements([])->has('totalcms/commerce'))->toBeFalse();
});

it('passes every product on a Development license', function (): void {
	expect(entitlements([], dev: true)->has('acme/anything'))->toBeTrue();
});

// A trial is where someone decides whether to buy, so it opens Total CMS's
// own paid products, as it opens every edition. It does not open another
// seller's product: those are sold on that seller's terms.
it('passes Total CMS\'s own products on a running trial', function (): void {
	expect(trialEntitlements()->has('totalcms/commerce'))->toBeTrue()
		->and(trialEntitlements()->has('totalcms/s3-uploads'))->toBeTrue();
});

it('does not pass another seller\'s product on a trial', function (string $product): void {
	expect(trialEntitlements()->has($product))->toBeFalse();
})->with(['acme/anything', 'totalcms', 'totalcmsx/commerce', 'acme/totalcms/commerce', '']);

it('passes nothing once the trial has expired', function (): void {
	expect(trialEntitlements(running: false)->has('totalcms/commerce'))->toBeFalse();
});

it('reads updatesUntil from the license entry', function (): void {
	$e = entitlements([['product' => 'acme/x', 'updatesUntil' => '2027-11-02'], 'acme/y']);

	expect($e->updatesUntil('acme/x'))->toBe('2027-11-02')
		->and($e->updatesUntil('acme/y'))->toBeNull()
		->and($e->updatesUntil('acme/never-bought'))->toBeNull();
});

it('is false, not an error, when the license cannot be read', function (): void {
	$license = new LicenseEntitlementSource(StubLicenseValidator::failing());
	$e       = new Entitlements([$license], $license, licenseStatus(dev: false));

	expect($e->has('totalcms/commerce'))->toBeFalse()
		->and($license->find('totalcms/commerce'))->toBeNull()
		->and($e->updatesUntil('totalcms/commerce'))->toBeNull();
});
