<?php

declare(strict_types=1);

use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Query\Service\TemplateQuery;
use TotalCMS\Domain\Twig\Adapter\ViewTwigAdapter;

function viewAdapterWith(TemplateQuery $query): ViewTwigAdapter
{
	return new ViewTwigAdapter(test()->createMock(DataViewFetcher::class), test()->createMock(DataViewLister::class), $query);
}

test('cms.view.query() returns a page of the view with what pagination needs', function (): void {
	$query = $this->createMock(TemplateQuery::class);
	$query->expects($this->once())->method('view')
		->with('top-posts', ['sort' => 'score:desc', 'limit' => 2])
		->willReturn(new QueryResult([['id' => 'y'], ['id' => 'z']], 3, 2, 0));

	expect(viewAdapterWith($query)->query('top-posts', ['sort' => 'score:desc', 'limit' => 2]))
		->toMatchArray(['total' => 3, 'page' => 1, 'pages' => 2, 'hasMore' => true])
		->objects->toBe([['id' => 'y'], ['id' => 'z']]);
});

test('a view that fails to load gives an empty result instead of an error', function (): void {
	$query = $this->createMock(TemplateQuery::class);
	$query->method('view')->willThrowException(new RuntimeException('Data view not found'));

	expect(viewAdapterWith($query)->query('missing'))->toMatchArray(['objects' => [], 'total' => 0, 'pages' => 0]);
});
