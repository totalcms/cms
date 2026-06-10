<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class CollectionTwigAdapterSearchScoredTest extends TestCase
{
	private function makeAdapter(IndexSearcher $indexSearcher): CollectionTwigAdapter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		return new CollectionTwigAdapter(
			$this->createMock(Config::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionEditionService::class),
			$this->createMock(IndexReader::class),
			$indexSearcher,
			$this->createMock(ObjectFetcher::class),
			$this->createMock(ObjectUrlBuilder::class),
			$loggerFactory,
		);
	}

	public function testSearchScoredReturnsRankedPlainArrays(): void
	{
		$indexSearcher = $this->createMock(IndexSearcher::class);
		$indexSearcher->expects($this->once())
			->method('searchScored')
			->with('products', 'menu', ['name' => 3.0])
			->willReturn(collect([
				['id' => 'burger', 'name' => 'Burger'],
				['id' => 'rails', 'name' => 'Rails'],
			]));

		$adapter = $this->makeAdapter($indexSearcher);

		$out = $adapter->searchScored('products', 'menu', ['name' => 3.0]);

		$this->assertSame([
			['id' => 'burger', 'name' => 'Burger'],
			['id' => 'rails', 'name' => 'Rails'],
		], $out);
	}

	public function testSearchScoredReturnsEmptyArrayOnException(): void
	{
		$indexSearcher = $this->createMock(IndexSearcher::class);
		$indexSearcher->method('searchScored')
			->willThrowException(new \RuntimeException('index unreadable'));

		$adapter = $this->makeAdapter($indexSearcher);

		$this->assertSame([], $adapter->searchScored('products', 'menu'));
	}

	public function testSearchScoredDefaultsToNoWeights(): void
	{
		$indexSearcher = $this->createMock(IndexSearcher::class);
		$indexSearcher->expects($this->once())
			->method('searchScored')
			->with('products', 'menu', [])
			->willReturn(collect([]));

		$adapter = $this->makeAdapter($indexSearcher);

		$this->assertSame([], $adapter->searchScored('products', 'menu'));
	}
}
