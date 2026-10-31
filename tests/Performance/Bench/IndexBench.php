<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use PhpBench\Attributes as Bench;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;

#[Bench\ParamProviders('provideSizes')]
final class IndexBench extends BenchCase
{
	private const COLLECTION = 'posts';

	public function primeAndReset(): void
	{
		$this->container->get(IndexRepository::class)->fetchIndex(self::COLLECTION);
		$this->freshRequest();
	}

	public function clearAll(): void
	{
		$this->coldCaches();
	}

	/** First request after a Total CMS cache clear: the index comes off disk (OPcache stays warm, as on a live server). */
	#[Bench\BeforeMethods(['boot', 'clearAll'])]
	public function benchLoadCold(): void
	{
		$this->container->get(IndexRepository::class)->fetchIndex(self::COLLECTION);
	}

	/** A later request: the index comes out of Total CMS's cache (APCu, or the file cache when it does not fit in apc.shm_size). */
	#[Bench\BeforeMethods(['boot', 'primeAndReset'])]
	public function benchLoadWarm(): void
	{
		$this->container->get(IndexRepository::class)->fetchIndex(self::COLLECTION);
	}

	/** What every object save costs today (survey item 1). */
	#[Bench\BeforeMethods(['boot'])]
	public function benchRebuild(): void
	{
		$this->container->get(IndexBuilder::class)->buildIndex(self::COLLECTION);
	}
}
