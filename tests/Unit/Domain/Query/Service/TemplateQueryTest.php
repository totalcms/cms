<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\ObjectSearcher;
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

		return match ($viewId) {
			'top-posts' => [['id' => 'x', 'title' => 'Xray', 'score' => 3], ['id' => 'y', 'title' => 'Yankee', 'score' => 9], ['id' => 'z', 'title' => 'Zulu', 'score' => 5]],
			'rated'     => [
				['id' => 'p1', 'rating' => ['average' => 4.5, 'total' => 12]],
				['id' => 'p2', 'rating' => ['average' => 3.9, 'total' => 40]],
				['id' => 'p3', 'rating' => ['average' => 10, 'total' => 2]],
				['id' => 'p4'],
			],
			default => ['posts' => 12, 'pages' => 3],
		};
	});

	$this->query = new TemplateQuery($reader, new IndexSearcher($reader, new ObjectSearcher()), $views, $cache);
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

test('search matches every word, then filters, sorts and pages', function (): void {
	$result = $this->query->collection('posts', [
		'search' => 'news',
		'filter' => [['property' => 'draft', 'operator' => 'isfalse']],
		'sort'   => 'date:desc',
		'limit'  => 1,
	]);

	expect(array_column($result->items, 'id'))->toBe(['d']);
	expect($result->total)->toBe(2);
});

test('search keeps search() syntax: or and field:value', function (): void {
	expect(array_column($this->query->collection('posts', ['search' => 'alpha or bravo'])->items, 'id'))->toBe(['a', 'b']);
	expect(array_column($this->query->collection('posts', ['search' => 'title:delta'])->items, 'id'))->toBe(['d']);
});

test('an empty search lists everything instead of nothing', function (): void {
	expect($this->query->collection('posts', ['search' => '   '])->total)->toBe(4);
});

test('search text that differs only in case and spacing shares a cache entry', function (): void {
	$this->query->collection('posts', ['search' => 'Alpha ', 'limit' => 10]);
	$this->query->collection('posts', ['search' => '  alpha', 'limit' => 10]);

	expect($this->indexReads)->toBe(1);
});

test('search text is capped before it becomes part of the cache key', function (): void {
	$this->query->collection('posts', ['search' => str_repeat('a', 500)]);
	$this->query->collection('posts', ['search' => str_repeat('a', 300)]);

	expect($this->indexReads)->toBe(1);
});

test('searchPriority ranks a match in an earlier field first when there is no sort', function (): void {
	$reader = $this->createMock(IndexReader::class);
	$reader->method('fetchIndex')->willReturn(new IndexData([
		['id' => 'body', 'title' => 'Other', 'body' => 'pasta night'],
		['id' => 'title', 'title' => 'Pasta', 'body' => 'dinner'],
	]));
	$cache = $this->createMock(CacheManager::class);
	$cache->method('queryVersion')->willReturn('v1');
	$query = new TemplateQuery($reader, new IndexSearcher($reader, new ObjectSearcher()), $this->createMock(DataViewFetcher::class), $cache);

	expect(array_column($query->collection('dinners', ['search' => 'pasta', 'searchPriority' => 'title, body'])->items, 'id'))->toBe(['title', 'body']);
});

test('a Data View can be searched too', function (): void {
	expect(array_column($this->query->view('top-posts', ['search' => 'yankee'])->items, 'id'))->toBe(['y']);
});

test('a shuffle is fresh on every call; only the matches are cached', function (): void {
	$orders = [];
	for ($i = 0; $i < 20; $i++) {
		$orders[] = implode(',', array_column($this->query->collection('posts', ['sort' => 'shuffle', 'limit' => 4])->items, 'id'));
	}

	expect(count(array_unique($orders)))->toBeGreaterThan(1);
	expect($this->indexReads)->toBe(1);
	expect($this->query->collection('posts', ['sort' => [['shuffle' => true]], 'filter' => [['property' => 'category', 'value' => 'news']]])->total)->toBe(3);
});

test('sort and filter reach into an object property with a dotted path', function (): void {
	// Ratings store {average, total} on the object and list by both.
	$sorted = $this->query->view('rated', ['filter' => [['property' => 'rating.average', 'operator' => 'isnotempty']], 'sort' => 'rating.average:desc']);
	expect(array_column($sorted->items, 'id'))->toBe(['p3', 'p1', 'p2'], '10 > 4.5 > 3.9: numeric, not string order');

	$popular = $this->query->view('rated', ['filter' => [['property' => 'rating.total', 'operator' => 'gt', 'value' => 10]], 'sort' => 'rating.total:desc']);
	expect(array_column($popular->items, 'id'))->toBe(['p2', 'p1']);
	expect($popular->total)->toBe(2);
});
