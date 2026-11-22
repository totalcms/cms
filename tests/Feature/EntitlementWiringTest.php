<?php

declare(strict_types=1);

use TotalCMS\Domain\License\Service\Entitlements;
use TotalCMS\Domain\License\Service\ExtensionLicense;

/*
 * Entitlements and ExtensionLicense take a list and a closure, which the
 * container cannot work out for itself. This proves it is told: the unit
 * tests build both by hand and would pass with no definition at all.
 */

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
});

test('the container builds Entitlements, over the site\'s license', function (): void {
	// The test license is Pro and carries no paid product.
	expect($this->c->get(Entitlements::class)->has('totalcms/commerce'))->toBeFalse();
});

test('the container builds ExtensionLicense, over the installed extensions', function (): void {
	$license = $this->c->get(ExtensionLicense::class);

	// A bundled extension that asks for Pro and names no paid product.
	expect($license->has('totalcms/s3-uploads'))->toBeTrue()
		->and($license->has('nobody/nothing'))->toBeFalse();
});
