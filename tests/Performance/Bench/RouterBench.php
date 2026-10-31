<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use PhpBench\Attributes as Bench;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Index\Repository\IndexRepository;

/**
 * Site Builder routing over 10, 100 and 500 pages (1000 posts each). Every
 * request walks every page (survey item 5); a miss also walks every collection.
 */
#[Bench\ParamProviders('providePageCounts')]
#[Bench\BeforeMethods(['boot', 'primeRouter'])]
final class RouterBench extends BenchCase
{
	private int $pages = 0;

	/** @param array{fixture:string} $params */
	public function primeRouter(array $params): void
	{
		$this->pages = (int)substr($params['fixture'], strlen('1000-p'));
		$this->container->get(IndexRepository::class)->fetchIndex('builder-pages');
		$this->container->get(PageRouter::class)->match('/page-1');
		$this->freshRequest();
	}

	public function benchMatchStatic(): void
	{
		$this->container->get(PageRouter::class)->match('/page-1');
	}

	/**
	 * A templated route. The router tries patterns longest first, so where
	 * this one lands in that order varies with the page count; the full walk
	 * is benchNoMatch.
	 */
	public function benchMatchTemplated(): void
	{
		$firstTemplated = (int)round($this->pages * 0.7) + 1;
		$this->container->get(PageRouter::class)->match('/section-' . $firstTemplated . '/some-id');
	}

	/** Worst case: every page pattern, then every collection URL, fails. */
	public function benchNoMatch(): void
	{
		$this->container->get(PageRouter::class)->match('/no/such/page');
	}
}
