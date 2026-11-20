<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Migration\Migration\FoldReverseSortIntoSortByMigration;

/**
 * The Reverse Sort setting is retired: Sort By takes the sort syntax, and a
 * collection that had Reverse Sort ticked gets its Sort By reversed, the order
 * its admin table showed before. The key itself leaves the stored file.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->container = $this->app->getContainer();

	// Write the stored settings the way an earlier version left them.
	$this->storeLegacy = function (string $id, array $settings): void {
		$this->container->get(CollectionFetcher::class)->fetchOrCreateReserved($id);
		$file = cmsDataDir() . "$id/.meta.json";
		$meta = array_merge((array)json_decode((string)file_get_contents($file), true), $settings);
		file_put_contents($file, (string)json_encode($meta));
		$this->container->get(CollectionFetcher::class)->clearCache($id);
	};

	$this->stored = fn (string $id): array => (array)json_decode((string)file_get_contents(cmsDataDir() . "$id/.meta.json"), true);
});

it('reverses Sort By where Reverse Sort was ticked, and drops the key', function (): void {
	($this->storeLegacy)('blog', ['sortBy' => 'date', 'reverseSort' => true]);
	($this->storeLegacy)('gallery', ['sortBy' => '-featured,title', 'reverseSort' => true]);

	$folded = $this->container->get(FoldReverseSortIntoSortByMigration::class)->run();

	expect($folded)->toBe(2)
		->and(($this->stored)('blog')['sortBy'])->toBe('-date')
		->and(($this->stored)('blog'))->not->toHaveKey('reverseSort')
		->and(($this->stored)('gallery')['sortBy'])->toBe('featured,-title');
});

it('keeps a natural rule in the colon form and shuffle as it is', function (): void {
	($this->storeLegacy)('blog', ['sortBy' => 'title:asc:natural', 'reverseSort' => true]);
	($this->storeLegacy)('gallery', ['sortBy' => 'shuffle', 'reverseSort' => true]);

	$this->container->get(FoldReverseSortIntoSortByMigration::class)->run();

	expect(($this->stored)('blog')['sortBy'])->toBe('title:desc:natural')
		->and(($this->stored)('gallery')['sortBy'])->toBe('shuffle');
});

it('reverses the id default when Sort By is empty', function (): void {
	($this->storeLegacy)('blog', ['sortBy' => '', 'reverseSort' => true]);

	$this->container->get(FoldReverseSortIntoSortByMigration::class)->run();

	expect(($this->stored)('blog')['sortBy'])->toBe('-id');
});

it('leaves a collection with Reverse Sort unticked alone', function (): void {
	($this->storeLegacy)('blog', ['sortBy' => 'title', 'reverseSort' => false]);

	$folded = $this->container->get(FoldReverseSortIntoSortByMigration::class)->run();

	expect($folded)->toBe(0)
		->and(($this->stored)('blog')['sortBy'])->toBe('title');
});

it('changes nothing when it runs a second time', function (): void {
	($this->storeLegacy)('blog', ['sortBy' => 'date', 'reverseSort' => true]);

	$migration = $this->container->get(FoldReverseSortIntoSortByMigration::class);
	$migration->run();

	expect($migration->run())->toBe(0)
		->and(($this->stored)('blog')['sortBy'])->toBe('-date');
});
