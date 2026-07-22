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

final class CollectionTwigAdapterObjectTest extends TestCase
{
	private function makeAdapter(ObjectFetcher $objectFetcher): CollectionTwigAdapter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		return new CollectionTwigAdapter(
			$this->createMock(Config::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionEditionService::class),
			$this->createMock(IndexReader::class),
			$this->createMock(IndexSearcher::class),
			$objectFetcher,
			$this->createMock(ObjectUrlBuilder::class),
			$loggerFactory,
		);
	}

	public function testNullIdReturnsEmptyArrayInsteadOfTypeError(): void
	{
		// A pretty-URL detail page visited without a slug renders templates
		// that pass getData.id = null. Must degrade, never throw.
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->expects($this->never())->method('fetchObject');

		expect($this->makeAdapter($objectFetcher)->object('blog', null))->toBe([]);
	}

	public function testEmptyIdReturnsEmptyArray(): void
	{
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->expects($this->never())->method('fetchObject');

		expect($this->makeAdapter($objectFetcher)->object('blog', ''))->toBe([]);
	}

	public function testFetchExceptionReturnsEmptyArray(): void
	{
		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('fetchObject')
			->willThrowException(new \RuntimeException('Unable to fetch object'));

		expect($this->makeAdapter($objectFetcher)->object('blog', 'missing-post'))->toBe([]);
	}
}
