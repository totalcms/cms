<?php

declare(strict_types=1);

namespace Tests\Unit\Renderer;

use PHPUnit\Framework\TestCase;
use TotalCMS\Renderer\OffsetPaginator;

final class OffsetPaginatorTest extends TestCase
{
	public function testFirstPage(): void
	{
		$paginator = new OffsetPaginator(total: 50, perPage: 10, offset: 0, baseUrl: '/api/test');

		$this->assertSame(1, $paginator->getCurrentPage());
		$this->assertSame(5, $paginator->getLastPage());
		$this->assertSame(50, $paginator->getTotal());
		$this->assertSame(10, $paginator->getCount());
		$this->assertSame(10, $paginator->getPerPage());
	}

	public function testMiddlePage(): void
	{
		$paginator = new OffsetPaginator(total: 50, perPage: 10, offset: 20, baseUrl: '/api/test');

		$this->assertSame(3, $paginator->getCurrentPage());
		$this->assertSame(10, $paginator->getCount());
	}

	public function testLastPage(): void
	{
		$paginator = new OffsetPaginator(total: 50, perPage: 10, offset: 40, baseUrl: '/api/test');

		$this->assertSame(5, $paginator->getCurrentPage());
		$this->assertSame(10, $paginator->getCount());
	}

	public function testPartialLastPage(): void
	{
		$paginator = new OffsetPaginator(total: 53, perPage: 10, offset: 50, baseUrl: '/api/test');

		$this->assertSame(6, $paginator->getCurrentPage());
		$this->assertSame(6, $paginator->getLastPage());
		$this->assertSame(3, $paginator->getCount());
	}

	public function testBeyondTotal(): void
	{
		$paginator = new OffsetPaginator(total: 10, perPage: 10, offset: 20, baseUrl: '/api/test');

		$this->assertSame(0, $paginator->getCount());
	}

	public function testEmptyResult(): void
	{
		$paginator = new OffsetPaginator(total: 0, perPage: 10, offset: 0, baseUrl: '/api/test');

		$this->assertSame(1, $paginator->getCurrentPage());
		$this->assertSame(1, $paginator->getLastPage());
		$this->assertSame(0, $paginator->getTotal());
		$this->assertSame(0, $paginator->getCount());
	}

	public function testGetUrlWithCleanBase(): void
	{
		$paginator = new OffsetPaginator(total: 50, perPage: 10, offset: 0, baseUrl: '/api/test');

		$this->assertSame('/api/test?offset=0&limit=10', $paginator->getUrl(1));
		$this->assertSame('/api/test?offset=10&limit=10', $paginator->getUrl(2));
		$this->assertSame('/api/test?offset=40&limit=10', $paginator->getUrl(5));
	}

	public function testGetUrlWithExistingQueryString(): void
	{
		$paginator = new OffsetPaginator(total: 50, perPage: 10, offset: 0, baseUrl: '/api/test?sort=date');

		$this->assertSame('/api/test?sort=date&offset=0&limit=10', $paginator->getUrl(1));
		$this->assertSame('/api/test?sort=date&offset=10&limit=10', $paginator->getUrl(2));
	}
}
