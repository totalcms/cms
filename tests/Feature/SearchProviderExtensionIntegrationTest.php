<?php

declare(strict_types=1);

use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Domain\Search\Service\SearchService;
use TotalCMS\Support\Config;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Search provider extension integration', function (): void {
	it('the SearchProviderRegistry is wired in the live container with the text fallback', function (): void {
		// Without enabling the Algolia extension OR a Pro license, Algolia is NOT
		// registered. This test verifies the registry is constructed (smoke test
		// for the container wiring from Chunk A) and the built-in 'text' provider
		// is always available.
		$registry = $this->app->getContainer()->get(SearchProviderRegistry::class);
		expect($registry)->toBeInstanceOf(SearchProviderRegistry::class);
		// Text fallback always available.
		expect($registry->get('text'))->not->toBeNull();
	});

	it('Algolia provider is not in registry on a default install (extension disabled)', function (): void {
		// Default bootstrap does not enable the Algolia extension — it must be
		// explicitly enabled by the operator. Confirm 'algolia' is absent.
		$registry = $this->app->getContainer()->get(SearchProviderRegistry::class);
		expect($registry->get('algolia'))->toBeNull();
	});

	it('SearchService falls back to text when active provider is missing keys', function (): void {
		$config                           = $this->app->getContainer()->get(Config::class);
		$config->search['activeProvider'] = 'algolia';

		$service = $this->app->getContainer()->get(SearchService::class);
		// Pass null collection so IndexSearcher doesn't try to load a missing schema.
		$out = $service->search(new SearchQuery(text: 'hello'));

		// Algolia not in registry (extension disabled in test bootstrap) +
		// empty data dir → text returns nothing. Just confirms no exception.
		expect($out)->toBeArray();
	});
});
