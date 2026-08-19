<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JumpStart;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Data\JumpStartExportOptions;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LoggerFactory;

final class JumpStartExportCurrentDataFilterTest extends TestCase
{
	private function makeExporter(): JumpStartExporter
	{
		$collectionLister = $this->createMock(CollectionLister::class);
		$schemaLister     = $this->createMock(SchemaLister::class);
		$schemaFetcher    = $this->createMock(SchemaFetcher::class);
		$objectFetcher    = $this->createMock(ObjectFetcher::class);
		$indexReader      = $this->createMock(IndexReader::class);
		$templateLister   = $this->createMock(TemplateLister::class);
		$templateFetcher  = $this->createMock(TemplateFetcher::class);
		$cacheManager     = $this->createMock(CacheManager::class);
		$loggerFactory    = $this->createMock(LoggerFactory::class);

		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		// Seed two custom collections: blog + team
		$blog         = new CollectionData();
		$blog->id     = 'blog';
		$blog->schema = 'blog-custom';

		$team         = new CollectionData();
		$team->id     = 'team';
		$team->schema = 'team-custom';

		$collectionLister->method('listAllCollections')->willReturn([$blog, $team]);

		// Each collection has one object in its index
		$indexReader->method('fetchIndex')->willReturnCallback(
			fn (string $id): IndexData => new IndexData([['id' => "{$id}-obj-1"]])
		);

		// Return a minimal ObjectData for fetched objects
		$objectFetcher->method('fetchObject')->willReturnCallback(
			fn (string $collectionId, string $objectId): ObjectData => new ObjectData($objectId, [])
		);

		// Schemas needed by processObjectData
		$blogSchema               = new SchemaData();
		$blogSchema->id           = 'blog-custom';
		$blogSchema->properties   = [];

		$teamSchema               = new SchemaData();
		$teamSchema->id           = 'team-custom';
		$teamSchema->properties   = [];

		$schemaFetcher->method('fetchSchema')->willReturnCallback(
			fn (string $id): SchemaData => match ($id) {
				'blog-custom' => $blogSchema,
				'team-custom' => $teamSchema,
				default       => new SchemaData(),
			}
		);

		// One custom schema per collection
		$schemaBlog     = new SchemaData();
		$schemaBlog->id = 'blog-custom';
		$schemaTeam     = new SchemaData();
		$schemaTeam->id = 'team-custom';
		$schemaLister->method('listCustomSchemas')->willReturn([$schemaBlog, $schemaTeam]);

		$templateLister->method('listBuilderTemplates')->willReturn([]);

		return new JumpStartExporter(
			$collectionLister,
			$schemaLister,
			$schemaFetcher,
			$objectFetcher,
			$indexReader,
			$templateLister,
			$templateFetcher,
			new JumpStartData(),
			$cacheManager,
			$this->createMock(BuilderOrderRepository::class),
			$loggerFactory,
		);
	}

	public function testExportCurrentDataHonorsCollectionAndObjectFilters(): void
	{
		$data = $this->makeExporter()->exportCurrentData(new JumpStartExportOptions(
			collectionFilter: ['blog'],
			objectFilter: ['blog'],
		));

		$customIds = array_map(fn (array $c): string => (string)$c['id'], $data->collections['custom']);
		expect($customIds)->toContain('blog');
		expect($customIds)->not()->toContain('team');

		foreach ($data->objects as $object) {
			expect($object['collection'])->toBe('blog');
		}
	}

	public function testEmptyFiltersExportNothingForThoseCategories(): void
	{
		$data = $this->makeExporter()->exportCurrentData(new JumpStartExportOptions(
			schemaFilter: [],
			collectionFilter: [],
			objectFilter: [],
			templateFilter: [],
		));

		expect($data->schemas)->toBe([]);
		expect($data->objects)->toBe([]);
		expect($data->templates)->toBe([]);
		expect($data->collections['custom'])->toBe([]);
	}

	public function testNullOptionsIsAFullExport(): void
	{
		$data = $this->makeExporter()->exportCurrentData();

		expect(count($data->objects))->toBeGreaterThan(0);
	}
}
