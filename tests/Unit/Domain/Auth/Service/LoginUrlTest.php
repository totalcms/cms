<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\LoginUrl;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Support\Config;

// Where a signed-out visitor goes when they reach something protected in a
// collection: the collection's accessCollection, or the default login.

function loginUrlFor(?CollectionData $collection, string $api = 'https://example.test'): LoginUrl
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->api = $api;

	$fetcher = test()->createStub(CollectionFetcher::class);
	$fetcher->method('fetchCollection')->willReturn($collection);

	return new LoginUrl($config, $fetcher);
}

describe('LoginUrl', function (): void {
	it('sends visitors to the default login when the collection names no login collection', function (): void {
		expect(loginUrlFor(new CollectionData())->forCollection('price-sheets', ''))
			->toBe('https://example.test/admin/login');
	});

	it('sends visitors to the collection\'s login collection', function (): void {
		$collection                   = new CollectionData();
		$collection->accessCollection = 'dealers';

		expect(loginUrlFor($collection)->forCollection('price-sheets', ''))
			->toBe('https://example.test/admin/login/dealers');
	});

	it('carries the return url as redirect', function (): void {
		$collection                   = new CollectionData();
		$collection->accessCollection = 'dealers';

		expect(loginUrlFor($collection)->forCollection('price-sheets', 'https://example.test/download/price-sheets/a/file'))
			->toBe('https://example.test/admin/login/dealers?redirect=' . urlencode('https://example.test/download/price-sheets/a/file'));
	});

	it('falls back to the default login when the collection is missing', function (): void {
		expect(loginUrlFor(null)->forCollection('gone', ''))->toBe('https://example.test/admin/login');
	});

	it('does not double the slash when the api ends in one', function (): void {
		expect(loginUrlFor(new CollectionData(), '/tcms/')->forCollection('x', ''))->toBe('/tcms/admin/login');
	});
});
