<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Query\Service\ObjectSearcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\TextSearchProvider;

final class TextSearchProviderTest extends TestCase
{
	public function testIdAndLabel(): void
	{
		$provider = new TextSearchProvider(
			$this->createMock(IndexFilter::class),
			$this->createMock(ObjectSearcher::class),
		);

		$this->assertSame('text', $provider->id());
		$this->assertSame('Text Search (built-in)', $provider->label());
	}

	public function testIsAvailableAlwaysTrue(): void
	{
		$provider = new TextSearchProvider(
			$this->createMock(IndexFilter::class),
			$this->createMock(ObjectSearcher::class),
		);

		$this->assertTrue($provider->isAvailable());
	}

	public function testIndexAndDeleteAreNoOps(): void
	{
		$filter   = $this->createMock(IndexFilter::class);
		$searcher = $this->createMock(ObjectSearcher::class);

		$filter->expects($this->never())->method($this->anything());
		$searcher->expects($this->never())->method($this->anything());

		$provider = new TextSearchProvider($filter, $searcher);

		$provider->index('blog', 'post-1', ['title' => 'Hello']);
		$provider->delete('blog', 'post-1');

		$this->assertTrue(true);
	}

	public function testSearchAppliesFilterThenObjectSearcher(): void
	{
		$filter   = $this->createMock(IndexFilter::class);
		$searcher = $this->createMock(ObjectSearcher::class);

		$indexItems = [
			['id' => 'post-1', 'collection' => 'blog', 'title' => 'Hooks'],
			['id' => 'post-2', 'collection' => 'blog', 'title' => 'Lures'],
		];

		$filter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('blog', ['exclude' => 'draft:true'])
			->willReturn($indexItems);

		$searcher->expects($this->once())
			->method('search')
			->with($indexItems, 'hooks')
			->willReturn($indexItems);

		$provider = new TextSearchProvider($filter, $searcher);

		$out = $provider->search(new SearchQuery(text: 'hooks', collection: 'blog'));

		$this->assertCount(2, $out);
		$this->assertSame('post-1', $out[0]->id);
		$this->assertSame('blog', $out[0]->collection);
		$this->assertGreaterThan($out[1]->score, $out[0]->score);
	}

	public function testSearchAdminPersonaAppliesNoFilter(): void
	{
		$filter   = $this->createMock(IndexFilter::class);
		$searcher = $this->createMock(ObjectSearcher::class);

		$indexItems = [
			['id' => 'draft-post', 'title' => 'Draft', 'draft' => true],
		];

		$filter->expects($this->once())
			->method('fetchFilteredIndex')
			->with('blog', [])  // empty options for admin — no draft exclusion
			->willReturn($indexItems);

		$searcher->expects($this->once())
			->method('search')
			->with($indexItems, 'draft')
			->willReturn($indexItems);

		$provider = new TextSearchProvider($filter, $searcher);

		$out = $provider->search(new SearchQuery(text: 'draft', collection: 'blog', persona: 'admin'));

		$this->assertCount(1, $out);
		$this->assertSame('draft-post', $out[0]->id);
	}

	public function testSearchHonoursLimit(): void
	{
		$filter   = $this->createMock(IndexFilter::class);
		$searcher = $this->createMock(ObjectSearcher::class);

		$allItems = array_map(
			fn (int $i): array => ['id' => "post-{$i}", 'title' => "T{$i}"],
			range(1, 20),
		);

		$filter->method('fetchFilteredIndex')->willReturn($allItems);
		$searcher->method('search')->willReturn($allItems);

		$provider = new TextSearchProvider($filter, $searcher);

		$out = $provider->search(new SearchQuery(text: 'foo', collection: 'blog', limit: 5));

		$this->assertCount(5, $out);
	}

	public function testSearchWithNullCollectionReturnsEmpty(): void
	{
		$filter   = $this->createMock(IndexFilter::class);
		$searcher = $this->createMock(ObjectSearcher::class);

		$filter->expects($this->never())->method('fetchFilteredIndex');
		$searcher->expects($this->never())->method('search');

		$provider = new TextSearchProvider($filter, $searcher);

		$out = $provider->search(new SearchQuery(text: 'hello'));

		$this->assertSame([], $out);
	}

	public function testRelevanceFalseKeepsLegacyAndBehavior(): void
	{
		$filter = $this->createMock(IndexFilter::class);
		$items  = [
			['id' => 'burger', 'name' => 'Burger', 'tags' => ['nav', 'menu', 'hamburger']],
		];
		$filter->method('fetchFilteredIndex')->willReturn($items);

		$provider = new TextSearchProvider($filter, new ObjectSearcher());

		$out = $provider->search(new SearchQuery(
			text: 'hamburger menu navigation',
			collection: 'products',
			relevance: false,
		));

		// Legacy AND filter: "navigation" is absent -> no results.
		$this->assertSame([], $out);
	}

	public function testRelevanceTrueReturnsBestPartialFirstNormalized(): void
	{
		$filter = $this->createMock(IndexFilter::class);
		$items  = [
			['id' => 'burger', 'name' => 'Burger', 'tags' => ['nav', 'menu', 'hamburger']], // 2 terms
			['id' => 'glider', 'name' => 'Glider', 'description' => 'reading and navigation'], // 1 term
		];
		$filter->method('fetchFilteredIndex')->willReturn($items);

		$provider = new TextSearchProvider($filter, new ObjectSearcher());

		$out = $provider->search(new SearchQuery(
			text: 'hamburger menu navigation',
			collection: 'products',
			relevance: true,
		));

		$this->assertCount(2, $out);
		$this->assertSame('burger', $out[0]->id);
		$this->assertSame(1.0, $out[0]->score);   // top normalized to 1.0
		$this->assertSame('glider', $out[1]->id);
		$this->assertLessThan(1.0, $out[1]->score);
	}
}
