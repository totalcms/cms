<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Index\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Query\Service\ObjectFilter;
use TotalCMS\Domain\Query\Service\ObjectSearcher;
use TotalCMS\Domain\Query\Service\QueryPipeline;

/**
 * The owner scope is applied where the index is READ, before the pipeline
 * runs `ids`, `filter` and `search`. QueryPipeline gives `ids` precedence over
 * everything else, so applying the scope as a pipeline parameter would
 * let `?ids=` name another member's object and get it back.
 */
final class IndexQueryServiceTest extends TestCase
{
	/** @var list<string> cache prefixes the pipeline was asked to read */
	private array $cachePrefixes = [];

	private function service(): IndexQueryService
	{
		$index = new IndexData([
			['id' => 'alex-site', 'owner' => 'alex', 'draft' => false, 'title' => 'Alex'],
			['id' => 'sam-site', 'owner' => 'sam', 'draft' => false, 'title' => 'Sam'],
			['id' => 'alex-draft', 'owner' => 'alex', 'draft' => true, 'title' => 'Alex draft'],
			['id' => 'other-alex', 'owner' => 'Alex', 'draft' => false, 'title' => 'Other Alex'],
		]);
		$reader = $this->createMock(IndexReader::class);
		$reader->method('fetchIndex')->willReturn($index);

		$cache = $this->createMock(CacheManager::class);
		$cache->method('getApiResponse')->willReturnCallback(function (string $prefix): mixed {
			$this->cachePrefixes[] = $prefix;

			return null;
		});
		$cache->method('storeApiResponse')->willReturn(true);

		$objectFilter = new ObjectFilter();
		$pipeline     = new QueryPipeline($objectFilter, $this->createMock(ObjectSearcher::class), $cache);

		return new IndexQueryService(new IndexFilter($reader, $objectFilter), $pipeline);
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 *
	 * @return array<int,mixed>
	 */
	private function ids(array $items): array
	{
		return array_column($items, 'id');
	}

	public function testUnscopedQueryReturnsEverything(): void
	{
		$result = $this->service()->query('showcase', []);

		expect($this->ids($result->items))->toBe(['alex-site', 'sam-site', 'alex-draft', 'other-alex']);
		expect($result->total)->toBe(4);
	}

	public function testTheOwnerScopeNarrowsTheIndexBeforeTheQuery(): void
	{
		$result = $this->service()->query('showcase', [], new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false));

		expect($this->ids($result->items))->toBe(['alex-site', 'alex-draft']);
		expect($result->total)->toBe(2);
	}

	public function testIdsCannotWidenAScopedQuery(): void
	{
		$result = $this->service()->query('showcase', ['ids' => 'sam-site,alex-site'], new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false));

		expect($this->ids($result->items))->toBe(['alex-site']);
	}

	public function testCallerIncludeStillNarrowsWithinTheScope(): void
	{
		$result = $this->service()->query('showcase', ['include' => 'draft:false'], new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false));

		expect($this->ids($result->items))->toBe(['alex-site']);
	}

	public function testScopedQueriesUseTheirOwnCacheKey(): void
	{
		// A cached unscoped page must never be served to a scoped user, and two
		// scoped users must not share one.
		$service = $this->service();
		$service->query('showcase', []);
		$service->query('showcase', [], new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false));
		$service->query('showcase', [], new ReadScope(new OwnerScope('owner', 'sam'), published: false, drafts: false));

		expect($this->cachePrefixes)->toHaveCount(3);
		expect(count(array_unique($this->cachePrefixes)))->toBe(3);
		expect($this->cachePrefixes[0])->toBe('collection-query:showcase');
	}

	public function testAScopedQueryExcludesACaseVariantOwner(): void
	{
		// The include grammar is case-insensitive, so `owner:alex` used to
		// return Alex's objects too. The scope is exact, as the open check is.
		$result = $this->service()->query('showcase', [], new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false));

		expect($this->ids($result->items))->not->toContain('other-alex');
	}

	public function testAScopedQueryUsesADistinctCachePrefix(): void
	{
		$scope = new ReadScope(new OwnerScope('owner', 'alex'), published: false, drafts: false);
		$this->service()->query('showcase', [], $scope);

		expect($this->cachePrefixes)->toBe(['collection-query:showcase:' . $scope->cacheKey()]);
	}
}
