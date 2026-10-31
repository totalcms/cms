<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use PhpBench\Attributes as Bench;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchService;

/** The built-in `text` provider: a linear scan of the index (survey item 2). */
#[Bench\ParamProviders('provideSizes')]
#[Bench\BeforeMethods(['boot', 'primeIndex'])]
final class SearchBench extends BenchCase
{
	public function primeIndex(): void
	{
		$this->container->get(IndexRepository::class)->fetchIndex('posts');
		// A different term loads the search classes without priming the timed one.
		$this->container->get(SearchService::class)->search(new SearchQuery('dolorem', 'posts', 5));
		$this->freshRequest();
	}

	public function benchTextSearch(): void
	{
		$this->container->get(SearchService::class)->search(new SearchQuery('voluptatem', 'posts', 20));
	}
}
