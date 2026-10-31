<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use PhpBench\Attributes as Bench;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexQueryService;

/**
 * Collection queries as a listing page or the admin table runs them: filter
 * one category, newest first, 20 per page. Total CMS's index cache is warm
 * (APCu while the index fits in apc.shm_size, the file cache beyond — at 50k
 * on the default 32M it does not fit).
 */
#[Bench\ParamProviders('provideSizes')]
#[Bench\BeforeMethods(['boot', 'primeIndex'])]
final class QueryBench extends BenchCase
{
	private const COLLECTION = 'posts';

	private int $size = 0;

	/** @param array{fixture:string} $params */
	public function primeIndex(array $params): void
	{
		$this->size = (int)$params['fixture'];
		$this->container->get(IndexRepository::class)->fetchIndex(self::COLLECTION);
		// A different query: loads the pipeline's classes (a live worker has
		// them) without filling the response-cache entry the timed query uses.
		$this->container->get(IndexQueryService::class)->query(self::COLLECTION, ['include' => 'category:cat-8', 'sort' => 'title', 'limit' => '5']);
		$this->freshRequest();
	}

	public function benchFilterSortPage1(): void
	{
		$this->query(0);
	}

	/** The last page: same work as page 1 today, since the whole set is sorted first. */
	public function benchDeepPage(): void
	{
		$this->query(max(0, intdiv($this->size, 10) - 20));
	}

	/** The same query again on a later request: served from the response cache. */
	#[Bench\BeforeMethods(['boot', 'primeIndex', 'primeQuery'])]
	public function benchCachedRepeat(): void
	{
		$this->query(0);
	}

	public function primeQuery(): void
	{
		$this->query(0);
		$this->freshRequest();
	}

	private function query(int $offset): void
	{
		$this->container->get(IndexQueryService::class)->query(self::COLLECTION, [
			'include' => 'category:cat-3',
			'sort'    => '-date',
			'limit'   => '20',
			'offset'  => (string)$offset,
		]);
	}
}
