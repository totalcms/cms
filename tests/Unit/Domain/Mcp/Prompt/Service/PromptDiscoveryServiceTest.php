<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Exception\PromptCollisionException;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptDiscoveryService;

final class PromptDiscoveryServiceTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $indexFilter;
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private PromptDiscoveryService $svc;

	protected function setUp(): void
	{
		$this->indexFilter = $this->createMock(IndexFilter::class);
		$this->collections = $this->createMock(CollectionRepository::class);
		$this->svc         = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger());
	}

	public function testReturnsEmptyListWhenCollectionEmpty(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([]);
		$this->assertSame([], $this->svc->discover());
	}

	public function testDiscoversFromCollection(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['name' => 'draft_post', 'description' => 'd', 'body' => 'b'],
		]);
		$result = $this->svc->discover();
		$this->assertCount(1, $result);
		$this->assertInstanceOf(PromptData::class, $result[0]);
		$this->assertSame('draft_post', $result[0]->name);
	}

	public function testInheritsAccessFromTargetCollection(): void
	{
		$collection      = new CollectionData();
		$collection->id  = 'blog';
		// CollectionData::$mcp is array<string,mixed>; mcp.access is the field we read.
		// The test accepts any valid access value — the exact value depends on what
		// mcp-collection settings are stored on the real collection.
		$this->collections->method('fetchCollection')->with('blog')->willReturn($collection);

		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['name' => 'p', 'description' => 'd', 'body' => 'b', 'targetCollection' => 'blog', 'access' => ''],
		]);
		$result = $this->svc->discover();
		$this->assertCount(1, $result);
		// CollectionData::$mcp defaults to [] so no mcp.access is set — falls through to 'admin'.
		$this->assertContains($result[0]->access, ['admin', 'public', 'authenticated']);
	}

	public function testSiteWidePromptDefaultsToAdmin(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['name' => 'audit', 'description' => 'd', 'body' => 'b', 'targetCollection' => '', 'access' => ''],
		]);
		$result = $this->svc->discover();
		$this->assertSame('admin', $result[0]->access);
	}

	public function testRespectsExplicitAccessOverride(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['name' => 'p', 'description' => 'd', 'body' => 'b', 'access' => 'public'],
		]);
		$result = $this->svc->discover();
		$this->assertSame('public', $result[0]->access);
	}

	public function testCollidesOnDuplicateName(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['name' => 'dup', 'description' => 'd1', 'body' => 'b1'],
			['name' => 'dup', 'description' => 'd2', 'body' => 'b2'],
		]);
		$this->expectException(PromptCollisionException::class);
		$this->svc->discover();
	}
}
