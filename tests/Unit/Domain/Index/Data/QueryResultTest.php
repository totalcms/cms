<?php

namespace Tests\Unit\Domain\Index\Data;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Query\Data\QueryResult;

final class QueryResultTest extends TestCase
{
	public function testHasMoreWhenItemsRemain(): void
	{
		$result = new QueryResult(items: [], total: 50, limit: 10, offset: 0);

		$this->assertTrue($result->hasMore());
	}

	public function testHasMoreReturnsFalseOnLastPage(): void
	{
		$result = new QueryResult(items: [], total: 50, limit: 10, offset: 40);

		$this->assertFalse($result->hasMore());
	}

	public function testHasMoreReturnsFalseWhenExact(): void
	{
		$result = new QueryResult(items: [], total: 10, limit: 10, offset: 0);

		$this->assertFalse($result->hasMore());
	}

	public function testHasMoreReturnsFalseWhenBeyondTotal(): void
	{
		$result = new QueryResult(items: [], total: 5, limit: 10, offset: 10);

		$this->assertFalse($result->hasMore());
	}

	public function testNextOffset(): void
	{
		$result = new QueryResult(items: [], total: 50, limit: 10, offset: 20);

		$this->assertSame(30, $result->nextOffset());
	}

	public function testNextOffsetFromZero(): void
	{
		$result = new QueryResult(items: [], total: 50, limit: 15, offset: 0);

		$this->assertSame(15, $result->nextOffset());
	}

	public function testPaginationHeaders(): void
	{
		$result   = new QueryResult(items: [['id' => '1']], total: 50, limit: 10, offset: 20);
		$response = $result->withPaginationHeaders(new Response());

		$this->assertSame('50', $response->getHeaderLine('X-Total'));
		$this->assertSame('20', $response->getHeaderLine('X-Offset'));
		$this->assertSame('10', $response->getHeaderLine('X-Limit'));
		$this->assertSame('true', $response->getHeaderLine('X-Has-More'));
	}

	public function testPaginationHeadersLastPage(): void
	{
		$result   = new QueryResult(items: [['id' => '1']], total: 25, limit: 10, offset: 20);
		$response = $result->withPaginationHeaders(new Response());

		$this->assertSame('25', $response->getHeaderLine('X-Total'));
		$this->assertSame('false', $response->getHeaderLine('X-Has-More'));
	}
}
