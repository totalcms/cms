<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\DataView\Service\DataViewLister;

/**
 * Integration test for DataViewLister cache clearing.
 *
 * Verifies that ensureCollection() properly clears the negative cache
 * when creating a reserved collection. Without the fix, CollectionFetcher
 * caches a null for 'dataviews' on the first fetch, and the repository call
 * does not clear that cache, causing subsequent indexing to fail.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

it('clears the cache when creating the dataviews collection', function (): void {
	/** @var CollectionFetcher $fetcher */
	$fetcher = $this->app->getContainer()->get(CollectionFetcher::class);

	/** @var DataViewLister $lister */
	$lister = $this->app->getContainer()->get(DataViewLister::class);

	// Warm the negative cache: fetch 'dataviews' when it doesn't exist.
	// This stores null in CollectionFetcher's request-level cache.
	$notFound = $fetcher->fetchCollection('dataviews');
	expect($notFound)->toBeNull();

	// Call listViews(), which calls ensureCollection() to create the collection.
	// If the cache is not cleared, the next index build will fail because
	// SchemaFetcher::fetchSchemaForCollection('dataviews') -> IndexBuilder::buildIndex()
	// -> CollectionFetcher::fetchCollection() will still return the cached null.
	$views = $lister->listViews();

	// Assert the collection was created and is now accessible.
	expect($views)->toBeArray();

	// Assert the cache was cleared: the next fetch should return CollectionData, not null.
	$found = $fetcher->fetchCollection('dataviews');
	expect($found)->not->toBeNull();
	expect($found->id)->toBe('dataviews');
});
