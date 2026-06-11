<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('reference schema collection guard', function (): void {
	test('saveCollection rejects a reference schema', function (): void {
		$saver = $this->app->getContainer()->get(CollectionSaver::class);
		expect(fn () => $saver->saveCollection(['id' => 'totalcms', 'schema' => 'totalcms']))
			->toThrow(\DomainException::class);
	});

	test('saveCollection rejects totalcms-item too', function (): void {
		$saver = $this->app->getContainer()->get(CollectionSaver::class);
		expect(fn () => $saver->saveCollection(['id' => 'totalcms-item', 'schema' => 'totalcms-item']))
			->toThrow(\DomainException::class);
	});

	test('generateReservedCollection rejects a reference schema', function (): void {
		$factory = $this->app->getContainer()->get(\TotalCMS\Domain\Collection\Service\CollectionFactory::class);
		expect(fn () => $factory->generateReservedCollection('totalcms'))
			->toThrow(\DomainException::class);
	});

	test('fetchOrCreateReserved does not provision a reference schema', function (): void {
		$fetcher = $this->app->getContainer()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
		expect($fetcher->fetchOrCreateReserved('totalcms'))->toBeNull();
		expect($fetcher->fetchOrCreateReserved('totalcms-item'))->toBeNull();
	});
});
