<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Search\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Search\Data\SearchResult;

final class SearchResultTest extends TestCase
{
	public function testConstructsWithRequiredFields(): void
	{
		$result = new SearchResult(collection: 'blog', id: 'post-1', score: 0.87);

		$this->assertSame('blog', $result->collection);
		$this->assertSame('post-1', $result->id);
		$this->assertSame(0.87, $result->score);
		$this->assertNull($result->snippet);
	}

	public function testConstructsWithSnippet(): void
	{
		$result = new SearchResult(
			collection: 'blog',
			id: 'post-1',
			score: 0.87,
			snippet: 'A blog post about <em>hooks</em>.',
		);

		$this->assertSame('A blog post about <em>hooks</em>.', $result->snippet);
	}
}
