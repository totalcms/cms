<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Job;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Job\ReindexJob;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class ReindexJobTest extends TestCase
{
	public function testSkipsSilentlyWhenActiveIsText(): void
	{
		$job = new ReindexJob(
			new SearchProviderRegistry(),
			$this->createMock(ObjectFetcher::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => \Monolog\Level::Debug]),
			$this->makeConfig(['activeProvider' => 'text']),
		);

		// Should not throw, should just return.
		$job->run($this->makeJob(['object_id' => 'x', 'operation' => 'index']));

		$this->assertTrue(true);
	}

	public function testThrowsWhenProviderUnregistered(): void
	{
		$job = new ReindexJob(
			new SearchProviderRegistry(),
			$this->createMock(ObjectFetcher::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => \Monolog\Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia']),
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/algolia.*not registered/');

		$job->run($this->makeJob(['object_id' => 'x', 'operation' => 'index']));
	}

	public function testCallsProviderIndexOnIndexOperationWhenObjectExists(): void
	{
		$indexedCollection = null;
		$indexedId         = null;
		$indexedData       = null;

		$provider = $this->makeProvider(
			'algolia',
			indexCallback: function (string $c, string $id, array $data) use (&$indexedCollection, &$indexedId, &$indexedData): void {
				$indexedCollection = $c;
				$indexedId         = $id;
				$indexedData       = $data;
			},
		);
		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(true);
		$objData = $this->createMock(ObjectData::class);
		$objData->method('toArray')->willReturn(['id' => 'post-1', 'title' => 'X']);
		$fetcher->method('fetchObject')->willReturn($objData);

		$job = new ReindexJob(
			$registry,
			$fetcher,
			new LoggerFactory(['test' => new NullLogger(), 'level' => \Monolog\Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia'])
		);

		$job->run($this->makeJob(['object_id' => 'post-1', 'operation' => 'index']));

		$this->assertSame('blog', $indexedCollection);
		$this->assertSame('post-1', $indexedId);
		$this->assertSame(['id' => 'post-1', 'title' => 'X'], $indexedData);
	}

	public function testDeletesWhenObjectMissingForIndexOperation(): void
	{
		$deletedCollection = null;
		$deletedId         = null;

		$provider = $this->makeProvider(
			'algolia',
			deleteCallback: function (string $c, string $id) use (&$deletedCollection, &$deletedId): void {
				$deletedCollection = $c;
				$deletedId         = $id;
			},
		);
		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(false);

		$job = new ReindexJob(
			$registry,
			$fetcher,
			new LoggerFactory(['test' => new NullLogger(), 'level' => \Monolog\Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia'])
		);

		$job->run($this->makeJob(['object_id' => 'post-1', 'operation' => 'index']));

		$this->assertSame('blog', $deletedCollection);
		$this->assertSame('post-1', $deletedId);
	}

	public function testCallsProviderDeleteOnDeleteOperation(): void
	{
		$deletedId = null;

		$provider = $this->makeProvider(
			'algolia',
			deleteCallback: function (string $c, string $id) use (&$deletedId): void {
				$deletedId = $id;
			},
		);
		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$job = new ReindexJob(
			$registry,
			$this->createMock(ObjectFetcher::class),
			new LoggerFactory(['test' => new NullLogger(), 'level' => \Monolog\Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia'])
		);

		$job->run($this->makeJob(['object_id' => 'post-1', 'operation' => 'delete']));

		$this->assertSame('post-1', $deletedId);
	}

	public function testRethrowsWhenProviderThrows(): void
	{
		$provider = $this->makeProvider(
			'algolia',
			indexCallback: fn () => throw new \RuntimeException('boom'),
		);
		$registry = new SearchProviderRegistry();
		$registry->register($provider);

		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(true);
		$objData = $this->createMock(ObjectData::class);
		$objData->method('toArray')->willReturn(['id' => 'post-1']);
		$fetcher->method('fetchObject')->willReturn($objData);

		$job = new ReindexJob(
			$registry,
			$fetcher,
			new LoggerFactory(['test' => new NullLogger(), 'level' => \Monolog\Level::Debug]),
			$this->makeConfig(['activeProvider' => 'algolia'])
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('boom');

		$job->run($this->makeJob(['object_id' => 'post-1', 'operation' => 'index']));
	}

	/** @param array<string,mixed> $data */
	private function makeJob(array $data): JobData
	{
		$job = (new \ReflectionClass(JobData::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($job, 'id'))->setValue($job, 'job-test');
		(new \ReflectionProperty($job, 'type'))->setValue($job, 'search.reindex');
		(new \ReflectionProperty($job, 'collection'))->setValue($job, 'blog');
		(new \ReflectionProperty($job, 'payload'))->setValue($job, (string)json_encode($data));

		return $job;
	}

	private function makeProvider(string $id, ?\Closure $indexCallback = null, ?\Closure $deleteCallback = null): SearchProvider
	{
		return new class($id, $indexCallback, $deleteCallback) implements SearchProvider {
			public function __construct(
				private readonly string $id,
				private readonly ?\Closure $indexCallback,
				private readonly ?\Closure $deleteCallback,
			) {
			}

			public function id(): string
			{
				return $this->id;
			}

			public function label(): string
			{
				return ucfirst($this->id);
			}

			public function search(SearchQuery $query): array
			{
				return [];
			}

			public function isAvailable(): bool
			{
				return true;
			}

			public function index(string $collection, string $id, array $data): void
			{
				if ($this->indexCallback instanceof \Closure) {
					($this->indexCallback)($collection, $id, $data);
				}
			}

			public function delete(string $collection, string $id): void
			{
				if ($this->deleteCallback instanceof \Closure) {
					($this->deleteCallback)($collection, $id);
				}
			}
		};
	}

	/** @param array<string,mixed> $search */
	private function makeConfig(array $search): Config
	{
		$c = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty($c, 'search'))->setValue($c, $search);

		return $c;
	}
}
