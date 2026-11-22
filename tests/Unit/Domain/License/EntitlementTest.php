<?php

declare(strict_types=1);

use TotalCMS\Domain\License\Data\Entitlement;
use TotalCMS\Domain\License\Data\LicenseData;

// A license can carry entitlements: paid products the site may use beyond its
// edition. The license server sends {product, updatesUntil, scope}; a bare
// product id is tolerated for fixtures and older servers.

it('parses bare ids and full entries, and skips junk', function (): void {
	$data = LicenseData::fromApiResponse(['valid' => true, 'entitlements' => [
		'totalcms/commerce',
		['product' => 'totalcms/s3-uploads', 'updatesUntil' => '2027-11-02', 'scope' => 'license'],
		42, ['nope' => 1], 'Not An Id', ['product' => 'Bad Id'],
	]]);

	expect(array_map(fn (Entitlement $e): string => $e->product, $data->entitlements))->toBe(['totalcms/commerce', 'totalcms/s3-uploads'])
		->and($data->entitlements[0]->updatesUntil)->toBeNull()
		->and($data->entitlements[0]->scope)->toBe('license')
		->and($data->entitlements[1]->updatesUntil)->toBe('2027-11-02');
});

it('treats a missing entitlements field as an empty list', function (): void {
	expect(LicenseData::fromApiResponse(['valid' => true])->entitlements)->toBe([])
		->and(LicenseData::fromApiResponse(['valid' => true, 'entitlements' => 'totalcms/commerce'])->entitlements)->toBe([])
		->and(LicenseData::fromArray(['valid' => true])->entitlements)->toBe([]);
});

it('round-trips entitlements through the cache array', function (): void {
	$data = LicenseData::fromApiResponse(['valid' => true, 'entitlements' => [
		['product' => 'totalcms/s3-uploads', 'updatesUntil' => '2027-11-02', 'scope' => 'site'],
		'totalcms/commerce',
	]]);

	$cached = LicenseData::fromArray((array)json_decode((string)json_encode($data->toArray()), true));

	expect($cached->entitlements)->toEqual($data->entitlements)
		->and($cached->toArray()['entitlements'])->toBe([
			['product' => 'totalcms/s3-uploads', 'updatesUntil' => '2027-11-02', 'scope' => 'site'],
			['product' => 'totalcms/commerce', 'updatesUntil' => null, 'scope' => 'license'],
		]);
});

it('builds one entitlement from a product id or an entry, and nothing from anything else', function (mixed $raw, ?string $product): void {
	expect(Entitlement::fromMixed($raw)?->product)->toBe($product);
})->with([
	'a product id'             => ['acme/thing', 'acme/thing'],
	'an entry'                 => [['product' => 'acme/thing-2'], 'acme/thing-2'],
	'an id with capitals'      => ['Acme/Thing', null],
	'an id with no vendor'     => ['thing', null],
	'an id with a third part'  => ['acme/thing/extra', null],
	'an entry with no product' => [['updatesUntil' => '2027-01-01'], null],
	'a number'                 => [7, null],
	'nothing'                  => [null, null],
]);
