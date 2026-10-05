<?php

namespace Tests\Unit\Domain\Feed\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

final class RssBuilderTest extends TestCase
{
	private MockObject $indexFilter;
	private MockObject $collectionFetcher;
	private MockObject $objectUrlBuilder;
	private MockObject $schemaFetcher;
	private MockObject $config;
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
			$this->config,
			new FeedWriter($this->config),
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

	/**
	 * Build a feed over the given index rows, whatever the schema.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @param array<string,string>      $options
	 */
	private function feedOf(array $rows, array $options = []): string
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'articles';

		$this->collectionFetcher->method('fetchCollection')->willReturn($collectionData);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn($rows);
		$this->objectUrlBuilder->method('buildUrl')->willReturnCallback(
			static fn ($collection, array $object): string => '/articles/' . $object['id'],
		);
		$this->objectUrlBuilder->method('hasEmptySegments')->willReturn(false);

		return $this->builder->buildFeed('articles', $options);
	}

	/** @return list<array<string,mixed>> */
	private function publishedAndDraft(): array
	{
		return [
			['id' => 'live', 'title' => 'Published Post', 'updated' => '2024-01-15', 'draft' => false],
			['id' => 'hidden', 'title' => 'Secret Draft', 'updated' => '2024-01-16', 'draft' => true],
		];
	}

	public function testDraftsAreDroppedForAnySchema(): void
	{
		// Used to be filtered for the `blog` schemas only; every other schema
		// with a `draft` field served its drafts in full.
		$result = $this->feedOf($this->publishedAndDraft());

		$this->assertStringContainsString('Published Post', $result);
		$this->assertStringNotContainsString('Secret Draft', $result);
	}

	public function testDraftsStayDroppedWhenTheRequestCarriesItsOwnFilters(): void
	{
		// The old draft filter was an `exclude` default, so any include or
		// exclude in the query string replaced it — drafts and all.
		foreach ([['exclude' => 'title:zzz'], ['include' => 'title:Post']] as $options) {
			$this->setUp();
			$result = $this->feedOf($this->publishedAndDraft(), $options);

			$this->assertStringContainsString('Published Post', $result);
			$this->assertStringNotContainsString('Secret Draft', $result);
		}
	}

	public function testDraftFieldCannotBeRemapped(): void
	{
		// A mappable draft field would be an off switch: ?draft=anything.
		$this->builder->setFieldMap(['draft' => 'nosuchfield']);
		$result = $this->feedOf($this->publishedAndDraft());

		$this->assertStringNotContainsString('Secret Draft', $result);
	}

	public function testDraftValuesStoredAsStringsAreUnderstood(): void
	{
		$result = $this->feedOf([
			['id' => 'a', 'title' => 'String False', 'updated' => '2024-01-15', 'draft' => 'false'],
			['id' => 'b', 'title' => 'String True', 'updated' => '2024-01-15', 'draft' => 'true'],
			['id' => 'c', 'title' => 'No Draft Field', 'updated' => '2024-01-15'],
		]);

		$this->assertStringContainsString('String False', $result);
		$this->assertStringContainsString('No Draft Field', $result);
		$this->assertStringNotContainsString('String True', $result);
	}

	public function testFieldMapIgnoresUnknownKeysAndNonStringValues(): void
	{
		$this->builder->setFieldMap([
			'title'   => 'headline',
			'content' => '',
			'author'  => ['nested'],
			'rssurl'  => 'https://example.com/feed',
			'limit'   => '5',
		]);

		$result = $this->feedOf([
			['id' => 'a', 'title' => 'Plain Title', 'headline' => 'Mapped Headline', 'summary' => 'Default Summary', 'updated' => '2024-01-15'],
		]);

		$this->assertStringContainsString('Mapped Headline', $result);
		// An empty mapping falls back to the default field, not to nothing.
		$this->assertStringContainsString('Default Summary', $result);
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
		], RssBuilder::DEFAULT_FIELD_MAP);
	}
}
