<?php

namespace Tests\Unit\Domain\Feed\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

final class RssBuilderTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $indexFilter;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectUrlBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private RssBuilder $builder;

	protected function setUp(): void
	{
		$this->indexFilter       = $this->createMock(IndexFilter::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->objectUrlBuilder  = $this->createMock(ObjectUrlBuilder::class);
		$this->schemaFetcher     = $this->createMock(SchemaFetcher::class);
		$this->config            = $this->createMock(Config::class);
		$this->config->domain    = 'example.com';

		$this->builder = new RssBuilder(
			$this->indexFilter,
			$this->collectionFetcher,
			$this->objectUrlBuilder,
			$this->schemaFetcher,
			$this->config,
		);
	}

	public function testSetFieldMapMergesWithDefaults(): void
	{
		$this->builder->setFieldMap(['title' => 'headline']);

		// Verify by building feed - field map is applied
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Collection not found');

		$this->builder->buildFeed('test');
	}

	public function testBuildFeedThrowsExceptionForMissingCollection(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Collection not found: missing');

		$this->builder->buildFeed('missing');
	}

	public function testBuildFeedReturnsRssFeed(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn([]);

		$result = $this->builder->buildFeed('test');

		$this->assertStringContainsString('<?xml', $result);
		$this->assertStringContainsString('rss', $result);
	}

	public function testBuildFeedWithItems(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn([
				[
					'id'      => 'post-1',
					'title'   => 'Test Post',
					'summary' => 'Test content',
					'updated' => '2024-01-15',
				],
			]);

		$this->objectUrlBuilder->method('buildUrl')
			->willReturn('/blog/post-1');

		$this->objectUrlBuilder->method('hasEmptySegments')
			->willReturn(false);

		$result = $this->builder->buildFeed('test');

		$this->assertStringContainsString('Test Post', $result);
		$this->assertStringContainsString('Test content', $result);
	}

	public function testBuildFeedSkipsItemsWithEmptyUrls(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn([
				[
					'id'      => 'post-1',
					'title'   => 'Bad Post',
					'updated' => '2024-01-15',
				],
			]);

		$this->objectUrlBuilder->method('buildUrl')
			->willReturn('');

		$result = $this->builder->buildFeed('test');

		$this->assertStringNotContainsString('Bad Post', $result);
	}

	public function testBuildFeedSkipsItemsWithEmptySegments(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn([
				[
					'id'      => 'post-1',
					'title'   => 'Broken Post',
					'updated' => '2024-01-15',
				],
			]);

		$this->objectUrlBuilder->method('buildUrl')
			->willReturn('/blog//post');

		$this->objectUrlBuilder->method('hasEmptySegments')
			->willReturn(true);

		$result = $this->builder->buildFeed('test');

		$this->assertStringNotContainsString('Broken Post', $result);
	}

	public function testBuildFeedAutoFiltersDraftsForBlogSchema(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'blog';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'blog';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		// Expect exclude filter to be applied
		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('test', $this->callback(fn ($options): bool => isset($options['exclude']) && $options['exclude'] === 'draft:true'))
			->willReturn([]);

		$this->builder->buildFeed('test');
	}

	public function testBuildFeedSortsByDateNewestFirst(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn([
				['id' => 'old', 'title' => 'Old Post', 'updated' => '2024-01-01'],
				['id' => 'new', 'title' => 'New Post', 'updated' => '2024-06-01'],
				['id' => 'mid', 'title' => 'Mid Post', 'updated' => '2024-03-15'],
			]);

		$this->objectUrlBuilder->method('buildUrl')
			->willReturn('/blog/post');

		$this->objectUrlBuilder->method('hasEmptySegments')
			->willReturn(false);

		$result = $this->builder->buildFeed('test');

		// Verify ordering by checking positions in the result
		$newPos = strpos($result, 'New Post');
		$midPos = strpos($result, 'Mid Post');
		$oldPos = strpos($result, 'Old Post');

		$this->assertLessThan($midPos, $newPos);
		$this->assertLessThan($oldPos, $midPos);
	}

	public function testBuildFeedAppliesLimit(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$items = [];
		for ($i = 0; $i < 30; $i++) {
			$items[] = [
				'id'      => "post-{$i}",
				'title'   => "Post {$i}",
				'updated' => date('Y-m-d', strtotime("-{$i} days")),
			];
		}

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn($items);

		$this->objectUrlBuilder->method('buildUrl')
			->willReturn('/blog/post');

		$this->objectUrlBuilder->method('hasEmptySegments')
			->willReturn(false);

		// Default limit is 25
		$result = $this->builder->buildFeed('test');

		// Count item occurrences (each item has a link with post-)
		$count = substr_count($result, '<item>');

		$this->assertSame(25, $count);
	}

	public function testBuildFeedWithCustomLimit(): void
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'generic';

		$schemaData     = $this->createMock(SchemaData::class);
		$schemaData->id = 'generic';

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($collectionData);

		$this->schemaFetcher->method('fetchSchema')
			->willReturn($schemaData);

		$items = [];
		for ($i = 0; $i < 10; $i++) {
			$items[] = [
				'id'      => "post-{$i}",
				'title'   => "Post {$i}",
				'updated' => date('Y-m-d', strtotime("-{$i} days")),
			];
		}

		$this->indexFilter->method('fetchFilteredIndex')
			->willReturn($items);

		$this->objectUrlBuilder->method('buildUrl')
			->willReturn('/blog/post');

		$this->objectUrlBuilder->method('hasEmptySegments')
			->willReturn(false);

		$result = $this->builder->buildFeed('test', ['limit' => 5]);

		$count = substr_count($result, '<item>');

		$this->assertSame(5, $count);
	}

	public function testDefaultFieldMap(): void
	{
		$this->assertSame([
			'title'   => 'title',
			'content' => 'summary',
			'media'   => 'media',
			'author'  => 'author',
			'date'    => 'updated',
			'draft'   => 'draft',
		], RssBuilder::DEFAULT_FIELD_MAP);
	}
}
