<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\TemplateQuery;

/*
 * cms.collection.query() and cms.view.query(): a page of a collection or a
 * Data View, filtered and sorted with the same rules as the filterCollection
 * and sortCollection filters, and cached so a repeat never loads it again.
 */

beforeEach(function (): void {
	$this->indexReads = 0;
	$this->endpoints  = [];
	$this->stored     = [];

	$reader = $this->createMock(IndexReader::class);
	$reader->method('fetchIndex')->willReturnCallback(function (): IndexData {
		$this->indexReads++;

		return new IndexData([
			['id' => 'a', 'title' => 'Alpha', 'category' => 'news', 'date' => '2026-01-03', 'draft' => false],
			['id' => 'b', 'title' => 'Bravo', 'category' => 'blog', 'date' => '2026-01-01', 'draft' => false],
			['id' => 'c', 'title' => 'Charlie', 'category' => 'news', 'date' => '2026-01-02', 'draft' => true],
			['id' => 'd', 'title' => 'Delta', 'category' => 'news', 'date' => '2026-01-04', 'draft' => false],
		]);
	});

	$cache = $this->createMock(CacheManager::class);
	$cache->method('queryVersion')->willReturnCallback(static fn (string $scope): string => $scope === 'dataviews' ? 'dv1' : 'v1');
	$cache->method('getApiResponse')->willReturnCallback(function (string $endpoint, array $params): mixed {
		$this->endpoints[] = $endpoint;

		return $this->stored[$endpoint . serialize($params)] ?? null;
	});
	$cache->method('storeApiResponse')->willReturnCallback(function (string $endpoint, array $params, mixed $value): bool {
		$this->stored[$endpoint . serialize($params)] = $value;

		return true;
	});

	$this->viewLoads = 0;
	$views           = $this->createMock(DataViewFetcher::class);
	$views->method('getViewData')->willReturnCallback(function (string $viewId): array {
		$this->viewLoads++;

		return $viewId === 'top-posts'
			? [['id' => 'x', 'title' => 'Xray', 'score' => 3], ['id' => 'y', 'title' => 'Yankee', 'score' => 9], ['id' => 'z', 'title' => 'Zulu', 'score' => 5]]
			: ['posts' => 12, 'pages' => 3];
	});

	$this->query = new TemplateQuery($reader, $views, $cache);
});

test('it filters with filterCollection rules, sorts and returns one page with the total', function (): void {
	$result = $this->query->collection('posts', [
		'filter' => [['property' => 'category', 'value' => 'news'], ['property' => 'draft', 'operator' => 'isfalse']],
		'sort'   => 'date:desc',
		'limit'  => 1,
		'page'   => 2,
	]);

	expect(array_column($result->items, 'id'))->toBe(['a']);
	expect($result->total)->toBe(2);
	expect($result->limit)->toBe(1);
	expect($result->offset)->toBe(1);
	expect($result->hasMore())->toBeFalse();
});

test('sort also takes sortCollection rules', function (): void {
	$result = $this->query->collection('posts', ['sort' => [['property' => 'title', 'reverse' => true]]]);

	expect(array_column($result->items, 'id'))->toBe(['d', 'c', 'b', 'a']);
});

test('without a limit it returns every match', function (): void {
	$result = $this->query->collection('posts', ['filter' => [['property' => 'category', 'value' => 'news']]]);

	expect(array_column($result->items, 'id'))->toBe(['a', 'c', 'd']);
	expect($result->total)->toBe(3);
	expect($result->hasMore())->toBeFalse();
});

test('an offset works in place of a page', function (): void {
	expect(array_column($this->query->collection('posts', ['limit' => 2, 'offset' => 3])->items, 'id'))->toBe(['d']);
});

test('a repeat of the same query does not read the index again', function (): void {
	$options = ['sort' => 'date:desc', 'limit' => 2];
	$first   = $this->query->collection('posts', $options);

	$second = $this->query->collection('posts', $options);

	expect($this->indexReads)->toBe(1);
	expect($second->items)->toBe($first->items);
});

test('different options are cached apart', function (): void {
	$this->query->collection('posts', ['limit' => 1, 'page' => 1]);
	$page2 = $this->query->collection('posts', ['limit' => 1, 'page' => 2]);

	expect(array_column($page2->items, 'id'))->toBe(['b']);
	expect($this->indexReads)->toBe(2);
});

test('the cache key carries the collection query version', function (): void {
	$this->query->collection('posts', []);

	expect($this->endpoints)->toBe(['collection-template-query:posts@v1']);
});

test('a filter that reads the clock is cached for the current minute only', function (string $operator): void {
	// "past" today is not "past" in an hour: a result cached until the next
	// save would keep showing a scheduled post as unpublished.
	$this->query->collection('posts', ['filter' => [['property' => 'date', 'operator' => $operator, 'value' => 'now']]]);

	expect($this->endpoints[0])->toMatch('/^collection-template-query:posts@v1:t\d{12}$/');
})->with(['past', 'not-future', 'pastToday', 'thisMonth', 'after']);

test('a Data View is queried with the same options', function (): void {
	$result = $this->query->view('top-posts', ['filter' => [['property' => 'score', 'operator' => 'gt', 'value' => 4]], 'sort' => 'score:desc', 'limit' => 1]);

	expect(array_column($result->items, 'id'))->toBe(['y']);
	expect($result->total)->toBe(2);
	expect($result->hasMore())->toBeTrue();
});

test('a Data View query is cached under the dataviews version', function (): void {
	$this->query->view('top-posts', ['limit' => 2]);
	$this->query->view('top-posts', ['limit' => 2]);

	expect($this->endpoints[0])->toBe('view-template-query:top-posts@dv1');
	expect($this->viewLoads)->toBe(1);
});

test('a Data View whose output is not a list of objects gives an empty result', function (): void {
	$result = $this->query->view('stats-object', []);

	expect($result->items)->toBe([]);
	expect($result->total)->toBe(0);
});

test('a result gives templates what pagination needs', function (): void {
	expect((new QueryResult([['id' => 'a']], 7, 3, 3))->forTemplate())->toBe([
		'objects' => [['id' => 'a']],
		'total'   => 7,
		'limit'   => 3,
		'offset'  => 3,
		'page'    => 2,
		'pages'   => 3,
		'hasMore' => true,
	]);
	expect((new QueryResult([], 0, 0, 0))->forTemplate())->toMatchArray(['page' => 1, 'pages' => 0, 'hasMore' => false]);
});
