<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Search\Data\SearchQuery;

final class SearchQueryTest extends TestCase
{
	public function testConstructsWithDefaults(): void
	{
		$query = new SearchQuery(text: 'hello');

		$this->assertSame('hello', $query->text);
		$this->assertNull($query->collection);
		$this->assertSame(10, $query->limit);
		$this->assertSame(0, $query->offset);
		$this->assertSame('public', $query->persona);
		$this->assertSame('', $query->locale);
	}

	public function testConstructsWithAllFields(): void
	{
		$query = new SearchQuery(
			text: 'hooks',
			collection: 'blog',
			limit: 25,
			offset: 50,
			persona: 'authenticated',
			locale: 'en_US',
		);

		$this->assertSame('hooks', $query->text);
		$this->assertSame('blog', $query->collection);
		$this->assertSame(25, $query->limit);
		$this->assertSame(50, $query->offset);
		$this->assertSame('authenticated', $query->persona);
		$this->assertSame('en_US', $query->locale);
	}
}
