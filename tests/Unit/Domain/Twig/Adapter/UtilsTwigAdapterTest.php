<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use TotalCMS\Domain\Twig\Adapter\UtilsTwigAdapter;

beforeEach(function (): void {
	$this->adapter = new UtilsTwigAdapter();
	$_GET          = [];
});

afterEach(function (): void {
	$_GET = [];
});

// --- Basic include/exclude ---

test('single include filter', function (): void {
	$_GET = ['category' => 'travel'];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('category:travel');
	expect($result['exclude'])->toBe('');
});

test('single exclude filter with - prefix', function (): void {
	$_GET = ['tag' => '-beach'];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('');
	expect($result['exclude'])->toBe('tag:beach');
});

test('multiple include filters', function (): void {
	$_GET = ['category' => 'travel', 'status' => 'published'];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('category:travel,status:published');
});

test('mixed include and exclude', function (): void {
	$_GET = ['category' => 'travel', 'tag' => '-beach'];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('category:travel');
	expect($result['exclude'])->toBe('tag:beach');
});

// --- Sort and search ---

test('sort param extracted', function (): void {
	$_GET = ['sort' => '-date'];

	$result = $this->adapter->urlFilters();

	expect($result['sort'])->toBe('-date');
	expect($result['include'])->toBe('');
});

test('search param extracted', function (): void {
	$_GET = ['search' => 'adventure'];

	$result = $this->adapter->urlFilters();

	expect($result['search'])->toBe('adventure');
	expect($result['include'])->toBe('');
});

test('sort and search not treated as property filters', function (): void {
	$_GET = ['sort' => '-date', 'search' => 'hello', 'category' => 'news'];

	$result = $this->adapter->urlFilters();

	expect($result['sort'])->toBe('-date');
	expect($result['search'])->toBe('hello');
	expect($result['include'])->toBe('category:news');
});

// --- Custom param names ---

test('custom sort param name', function (): void {
	$_GET = ['orderby' => '-title'];

	$result = $this->adapter->urlFilters(['sort' => 'orderby']);

	expect($result['sort'])->toBe('-title');
	expect($result['include'])->toBe('');
});

test('custom search param name', function (): void {
	$_GET = ['q' => 'hello world'];

	$result = $this->adapter->urlFilters(['search' => 'q']);

	expect($result['search'])->toBe('hello world');
	expect($result['include'])->toBe('');
});

test('default sort param still works as filter when renamed', function (): void {
	$_GET = ['sort' => 'asc', 'orderby' => '-date'];

	$result = $this->adapter->urlFilters(['sort' => 'orderby']);

	expect($result['sort'])->toBe('-date');
	expect($result['include'])->toBe('sort:asc');
});

// --- Ignore list ---

test('ignored params are skipped', function (): void {
	$_GET = ['page' => '2', 'id' => 'abc', 'category' => 'travel'];

	$result = $this->adapter->urlFilters(['ignore' => 'page,id']);

	expect($result['include'])->toBe('category:travel');
});

// --- Array values (tags[]=a&tags[]=b) ---

test('array values create multiple include entries', function (): void {
	$_GET = ['tags' => ['travel', 'vacation']];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('tags:travel,tags:vacation');
});

test('array values with mixed include and exclude', function (): void {
	$_GET = ['tags' => ['travel', '-beach']];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('tags:travel');
	expect($result['exclude'])->toBe('tags:beach');
});

test('array values skip empty strings', function (): void {
	$_GET = ['tags' => ['travel', '', 'food']];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('tags:travel,tags:food');
});

// --- Edge cases ---

test('empty query string returns empty results', function (): void {
	$_GET = [];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('');
	expect($result['exclude'])->toBe('');
	expect($result['sort'])->toBe('');
	expect($result['search'])->toBe('');
});

test('empty values are skipped', function (): void {
	$_GET = ['category' => '', 'tag' => 'travel'];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('tag:travel');
});

test('all four fields populated', function (): void {
	$_GET = [
		'sort'     => '-date',
		'search'   => 'hello',
		'category' => 'news',
		'draft'    => '-true',
	];

	$result = $this->adapter->urlFilters();

	expect($result['sort'])->toBe('-date');
	expect($result['search'])->toBe('hello');
	expect($result['include'])->toBe('category:news');
	expect($result['exclude'])->toBe('draft:true');
});

test('boolean-style values', function (): void {
	$_GET = ['published' => 'true', 'draft' => '-true'];

	$result = $this->adapter->urlFilters();

	expect($result['include'])->toBe('published:true');
	expect($result['exclude'])->toBe('draft:true');
});
