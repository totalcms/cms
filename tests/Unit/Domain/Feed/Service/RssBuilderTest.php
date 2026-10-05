<?php

namespace Tests\Unit\Domain\Feed\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Feed\Exception\FeedDisabledException;
use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Domain\Feed\Service\RssBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Support\Config;

final class RssBuilderTest extends TestCase
{
	private MockObject $indexFilter;
	private MockObject $collectionFetcher;
	private MockObject $objectUrlBuilder;
	private MockObject $config;
	private RssBuilder $builder;

	protected function setUp(): void
	{
		$this->indexFilter       = $this->createMock(IndexFilter::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->objectUrlBuilder  = $this->createMock(ObjectUrlBuilder::class);
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

	/**
	 * Build the feed of a collection holding the given index rows.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @param array<string,mixed>       $feed   the collection's RSS Feed card
	 * @param array<string,mixed>       $params the request's query parameters
	 */
	private function feedOf(array $rows, array $feed = ['enabled' => true], array $params = []): string
	{
		$collectionData         = $this->createMock(CollectionData::class);
		$collectionData->schema = 'articles';
		$collectionData->feed   = $feed;

		$this->collectionFetcher->method('fetchCollection')->willReturn($collectionData);
		$this->indexFilter->method('fetchFilteredIndex')->willReturn($rows);
		$this->objectUrlBuilder->method('buildUrl')->willReturnCallback(
			static fn ($collection, array $object): string => (string)($object['url'] ?? '/articles/' . $object['id']),
		);
		$this->objectUrlBuilder->method('hasEmptySegments')->willReturnCallback(
			static fn (string $url): bool => str_contains($url, '//'),
		);

		return $this->builder->buildFeed('articles', $params);
	}

	/** @return list<array<string,mixed>> */
	private function publishedAndDraft(): array
	{
		return [
			['id' => 'live', 'title' => 'Published Post', 'updated' => '2024-01-15', 'draft' => false],
			['id' => 'hidden', 'title' => 'Secret Draft', 'updated' => '2024-01-16', 'draft' => true],
		];
	}

	// ── Which collections have a feed ─────────────────────────────────────

	public function testAMissingCollectionHasNoFeed(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$this->expectException(FeedDisabledException::class);

		$this->builder->buildFeed('missing');
	}

	public function testACollectionWithoutTheCardEnabledHasNoFeed(): void
	{
		// Every collection with a URL used to have one.
		foreach ([[], ['enabled' => false], ['enabled' => '']] as $feed) {
			$this->setUp();

			try {
				$this->feedOf($this->publishedAndDraft(), $feed);
				$this->fail('A feed was served for a collection that has not enabled one');
			} catch (FeedDisabledException $e) {
				$this->assertStringContainsString('articles', $e->getMessage());
			}
		}
	}

	public function testQueryParametersDoNotSwitchAFeedOn(): void
	{
		$this->expectException(FeedDisabledException::class);

		$this->feedOf($this->publishedAndDraft(), [], ['limit' => '5', 'content' => 'title', 'enabled' => '1']);
	}

	public function testAnEnabledCollectionServesItsFeed(): void
	{
		$result = $this->feedOf([
			['id' => 'post-1', 'title' => 'Test Post', 'summary' => 'Test content', 'updated' => '2024-01-15'],
		]);

		$this->assertStringContainsString('<?xml', $result);
		$this->assertStringContainsString('Test Post', $result);
		$this->assertStringContainsString('Test content', $result);
	}

	// ── What stays out ────────────────────────────────────────────────────

	public function testDraftsAreDroppedForAnySchema(): void
	{
		// Used to be filtered for the `blog` schemas only.
		$result = $this->feedOf($this->publishedAndDraft());

		$this->assertStringContainsString('Published Post', $result);
		$this->assertStringNotContainsString('Secret Draft', $result);
	}

	public function testDraftsStayDroppedWhateverTheRequestCarries(): void
	{
		// The old draft filter was an `exclude` default, so any include or
		// exclude in the query string replaced it — drafts and all.
		foreach ([['exclude' => 'title:zzz'], ['include' => 'title:Post'], ['draft' => 'nosuchfield'], ['hidden' => 'nosuchfield']] as $params) {
			$this->setUp();
			$result = $this->feedOf($this->publishedAndDraft(), ['enabled' => true], $params);

			$this->assertStringContainsString('Published Post', $result);
			$this->assertStringNotContainsString('Secret Draft', $result);
		}
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

	public function testTheHiddenFieldReplacesDraftAsTheMapping(): void
	{
		$rows = [
			['id' => 'a', 'title' => 'Visible Post', 'updated' => '2024-01-15', 'archived' => false],
			['id' => 'b', 'title' => 'Archived Post', 'updated' => '2024-01-15', 'archived' => true],
			['id' => 'c', 'title' => 'Draft Post', 'updated' => '2024-01-15', 'draft' => true],
		];

		$result = $this->feedOf($rows, ['enabled' => true, 'hidden' => 'archived']);

		$this->assertStringContainsString('Visible Post', $result);
		$this->assertStringNotContainsString('Archived Post', $result);
		// A real mapping: the collection chose `archived`, so `draft` no
		// longer hides anything. That is how a feed of drafts is published.
		$this->assertStringContainsString('Draft Post', $result);
	}

	public function testABlankHiddenFieldStillHidesDrafts(): void
	{
		// Unset must mean `draft`: every collection the migration enabled has
		// no value here.
		foreach ([['enabled' => true], ['enabled' => true, 'hidden' => ''], ['enabled' => true, 'hidden' => null]] as $feed) {
			$this->setUp();
			$result = $this->feedOf($this->publishedAndDraft(), $feed);

			$this->assertStringNotContainsString('Secret Draft', $result);
		}
	}

	public function testItemsWithBrokenUrlsAreSkipped(): void
	{
		$result = $this->feedOf([
			['id' => 'ok', 'title' => 'Valid Post', 'updated' => '2024-01-15'],
			['id' => 'none', 'title' => 'No Url Post', 'updated' => '2024-01-15', 'url' => ''],
			['id' => 'gap', 'title' => 'Empty Segment Post', 'updated' => '2024-01-15', 'url' => '/articles//gap'],
		]);

		$this->assertStringContainsString('Valid Post', $result);
		$this->assertStringNotContainsString('No Url Post', $result);
		$this->assertStringNotContainsString('Empty Segment Post', $result);
	}

	// ── Saved settings and what a request may override ────────────────────

	public function testTheFieldMappingComesFromTheSavedCard(): void
	{
		$rows = [
			['id' => 'a', 'title' => 'Plain Title', 'headline' => 'Mapped Headline', 'summary' => 'Default Summary', 'body' => 'Mapped Body', 'updated' => '2024-01-15'],
		];

		$result = $this->feedOf($rows, ['enabled' => true, 'title' => 'headline', 'content' => 'body']);

		$this->assertStringContainsString('Mapped Headline', $result);
		$this->assertStringContainsString('Mapped Body', $result);
		$this->assertStringNotContainsString('Default Summary', $result);
	}

	public function testABlankMappingFallsBackToTheDefaultField(): void
	{
		$result = $this->feedOf(
			[['id' => 'a', 'title' => 'Plain Title', 'summary' => 'Default Summary', 'updated' => '2024-01-15']],
			['enabled' => true, 'title' => '', 'content' => ''],
		);

		$this->assertStringContainsString('Plain Title', $result);
		$this->assertStringContainsString('Default Summary', $result);
	}

	public function testARequestCannotChooseFields(): void
	{
		// ?content=<field> used to put any indexed field into the feed.
		$rows = [
			['id' => 'a', 'title' => 'Plain Title', 'summary' => 'Public Summary', 'email' => 'private@example.com', 'updated' => '2024-01-15'],
		];

		$result = $this->feedOf($rows, ['enabled' => true], ['content' => 'email', 'title' => 'email', 'author' => 'email', 'media' => 'email', 'date' => 'email']);

		$this->assertStringContainsString('Public Summary', $result);
		$this->assertStringNotContainsString('private@example.com', $result);
	}

	public function testSavedFeedSettingsDescribeTheFeed(): void
	{
		$result = $this->feedOf(
			[['id' => 'a', 'title' => 'Post', 'updated' => '2024-01-15']],
			['enabled' => true, 'name' => 'Saved Name', 'description' => 'Saved description', 'link' => 'https://example.com/articles/', 'language' => 'en-us'],
		);

		$this->assertStringContainsString('<title>Saved Name</title>', $result);
		$this->assertStringContainsString('Saved description', $result);
		$this->assertStringContainsString('https://example.com/articles/', $result);
		$this->assertStringContainsString('en-us', $result);
	}

	public function testARequestMayRenameAndFilterButNotRelink(): void
	{
		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('articles', ['include' => 'category:news', 'exclude' => 'saved:exclude']);

		$result = $this->feedOf(
			[['id' => 'a', 'title' => 'Post', 'updated' => '2024-01-15']],
			['enabled' => true, 'name' => 'Saved Name', 'link' => 'https://example.com/articles/', 'include' => 'saved:include', 'exclude' => 'saved:exclude'],
			['name' => 'News Only', 'include' => 'category:news', 'link' => 'https://evil.example/', 'exclude' => ''],
		);

		$this->assertStringContainsString('<title>News Only</title>', $result);
		$this->assertStringContainsString('https://example.com/articles/', $result);
		$this->assertStringNotContainsString('evil.example', $result);
	}

	public function testTheFeedIsSortedNewestFirst(): void
	{
		$result = $this->feedOf([
			['id' => 'old', 'title' => 'Old Post', 'updated' => '2024-01-01'],
			['id' => 'new', 'title' => 'New Post', 'updated' => '2024-03-01'],
			['id' => 'mid', 'title' => 'Mid Post', 'updated' => '2024-02-01'],
		]);

		$this->assertLessThan(strpos($result, 'Mid Post'), strpos($result, 'New Post'));
		$this->assertLessThan(strpos($result, 'Old Post'), strpos($result, 'Mid Post'));
	}

	/** @return list<array<string,mixed>> */
	private function posts(int $count): array
	{
		$rows = [];
		for ($i = 1; $i <= $count; $i++) {
			$rows[] = ['id' => "post-$i", 'title' => "Post $i", 'updated' => sprintf('2024-01-%02d', min($i, 28))];
		}

		return $rows;
	}

	public function testTheLimitDefaultsToTwentyFive(): void
	{
		$this->assertSame(25, substr_count($this->feedOf($this->posts(30)), '<item>'));
	}

	public function testTheLimitComesFromTheCardAndARequestMayChangeIt(): void
	{
		$this->assertSame(10, substr_count($this->feedOf($this->posts(30), ['enabled' => true, 'limit' => 10]), '<item>'));

		$this->setUp();
		$this->assertSame(5, substr_count($this->feedOf($this->posts(30), ['enabled' => true, 'limit' => 10], ['limit' => '5']), '<item>'));

		$this->setUp();
		$this->assertSame(30, substr_count($this->feedOf($this->posts(30), ['enabled' => true, 'limit' => -1]), '<item>'));
	}

	public function testDefaultFieldMap(): void
	{
		$this->assertSame([
			'title'   => 'title',
			'content' => 'summary',
			'media'   => 'media',
			'author'  => 'author',
			'date'    => 'updated',
			'hidden'  => 'draft',
		], RssBuilder::DEFAULT_FIELD_MAP);
	}
}
