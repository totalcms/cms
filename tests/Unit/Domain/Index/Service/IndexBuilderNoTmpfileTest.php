<?php

declare(strict_types=1);

// Hosts harden PHP-FPM with disable_functions, and tmpfile() is on the
// usual lists (the ServerAvatar load-test box disables it). Shadowing it in
// the builder's namespace makes it fail here the way it does there.
namespace TotalCMS\Domain\Index\Service {
	function tmpfile(): false
	{
		return false;
	}
}

namespace Tests\Unit\Domain\Index\Service {
	use PHPUnit\Framework\TestCase;
	use Psr\Log\NullLogger;
	use TotalCMS\Domain\Collection\Service\CollectionFetcher;
	use TotalCMS\Domain\Collection\Service\CollectionSaver;
	use TotalCMS\Domain\Index\Repository\IndexRepository;
	use TotalCMS\Domain\Index\Service\IndexBuilder;
	use TotalCMS\Domain\JobQueue\Service\JobQueuer;
	use TotalCMS\Domain\Object\Data\ObjectData;
	use TotalCMS\Domain\Object\Service\ObjectFetcher;
	use TotalCMS\Domain\Schema\Data\SchemaData;
	use TotalCMS\Domain\Schema\Service\SchemaFetcher;
	use TotalCMS\Factory\LoggerFactory;

	final class IndexBuilderNoTmpfileTest extends TestCase
	{
		public function testAStreamedRebuildDoesNotNeedTmpfile(): void
		{
			$ids     = array_map(static fn (int $i): string => "post-{$i}", range(1, 501));
			$storage = $this->createMock(IndexRepository::class);
			$storage->method('withIndexLock')->willReturnCallback(static fn (string $c, callable $fn): mixed => $fn());
			$storage->method('fetchObjectIdsFromDisk')->willReturn($ids);
			$storage->method('fetchObjectFileTimes')->willReturn(array_fill_keys($ids, 0));
			$handle = fopen('php://memory', 'r+');
			$storage->method('openIndexStream')->willReturn($handle);
			$storage->expects($this->exactly(501))->method('writeIndexEntry');

			$schema        = new SchemaData();
			$schema->index = ['id', 'title'];
			$schemaFetcher = $this->createMock(SchemaFetcher::class);
			$schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

			$objectFetcher = $this->createMock(ObjectFetcher::class);
			$object             = $this->createMock(ObjectData::class);
			$object->properties = collect(['title' => new class {
				public function transform(): string
				{
					return 'A post';
				}
			}]);
			$objectFetcher->method('fetchObjectFromDisk')->willReturn($object);

			$loggerFactory = $this->createMock(LoggerFactory::class);
			$loggerFactory->method('addFileHandler')->willReturnSelf();
			$loggerFactory->method('createLogger')->willReturn(new NullLogger());

			$builder = new IndexBuilder(
				$storage,
				$objectFetcher,
				$schemaFetcher,
				$this->createMock(CollectionFetcher::class),
				$this->createMock(CollectionSaver::class),
				$this->createMock(JobQueuer::class),
				$loggerFactory,
			);

			$builder->buildIndex('blog');

			$this->assertSame(501, $builder->lastIndexedCount());
			fclose($handle);
		}
	}
}
