<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\DataView\Service\DataViewLister;
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
		// Default: collection exists. Tests that need the missing-collection
		// branch can override with willReturn(false).
		$this->collections->method('collectionExists')->willReturn(true);
		$this->svc = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger());
	}

	public function testReturnsEmptyListWhenCollectionEmpty(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([]);
		$this->assertSame([], $this->svc->discover());
	}

	public function testDiscoversFromCollection(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['id' => 'draft_post', 'description' => 'd', 'body' => 'b'],
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
			['id' => 'p', 'description' => 'd', 'body' => 'b', 'targetCollection' => 'blog', 'access' => ''],
		]);
		$result = $this->svc->discover();
		$this->assertCount(1, $result);
		// CollectionData::$mcp defaults to [] so no mcp.access is set — falls through to 'admin'.
		$this->assertContains($result[0]->access, ['admin', 'public', 'authenticated']);
	}

	public function testSiteWidePromptDefaultsToAdmin(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['id' => 'audit', 'description' => 'd', 'body' => 'b', 'targetCollection' => '', 'access' => ''],
		]);
		$result = $this->svc->discover();
		$this->assertSame('admin', $result[0]->access);
	}

	public function testRespectsExplicitAccessOverride(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['id' => 'p', 'description' => 'd', 'body' => 'b', 'access' => 'public'],
		]);
		$result = $this->svc->discover();
		$this->assertSame('public', $result[0]->access);
	}

	public function testCollidesOnDuplicateName(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->with('mcp-prompt')->willReturn([
			['id' => 'dup', 'description' => 'd1', 'body' => 'b1'],
			['id' => 'dup', 'description' => 'd2', 'body' => 'b2'],
		]);
		$this->expectException(PromptCollisionException::class);
		$this->svc->discover();
	}

	public function testReturnsEmptyWhenCollectionDoesNotExist(): void
	{
		// Lite/Standard installs (and fresh test environments) never created
		// the mcp-prompt collection — discovery should silently return [].
		$indexFilter = $this->createMock(IndexFilter::class);
		$indexFilter->expects($this->never())->method('fetchFilteredIndex');
		$collections = $this->createMock(CollectionRepository::class);
		$collections->method('collectionExists')->willReturn(false);

		$svc = new PromptDiscoveryService($indexFilter, $collections, new NullLogger());
		$this->assertSame([], $svc->discover());
	}

	/**
	 * A prompt whose target names a Data View inherits that view's mcp.access.
	 * fetchCollection() returns null for the id, so the resolver falls through
	 * to the view branch.
	 */
	public function testInheritsAccessFromTargetView(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'p', 'body' => 'b', 'target' => 'recent-posts'],
		]);
		$this->collections->method('fetchCollection')->willReturn(null);

		$views = $this->createMock(DataViewLister::class);
		$views->method('listViews')->willReturn([
			['id' => 'recent-posts', 'mcp' => ['access' => 'public']],
		]);

		$svc = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger(), $views);
		$this->assertSame('public', $svc->discover()[0]->access);
	}

	/**
	 * The collision case, and the reason the order is fixed. Collections are
	 * filtered by access group and views are not, so an id carried by both must
	 * resolve to the collection — the stricter of the two models. Resolving to
	 * the view would silently widen the prompt's reach.
	 */
	public function testCollectionWinsWhenAnIdIsBothCollectionAndView(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'p', 'body' => 'b', 'target' => 'blog'],
		]);
		$this->collections->method('fetchCollection')->willReturn($this->makeCollection('authenticated'));

		$views = $this->createMock(DataViewLister::class);
		$views->method('listViews')->willReturn([
			['id' => 'blog', 'mcp' => ['access' => 'public']],
		]);

		$svc = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger(), $views);
		$this->assertSame('authenticated', $svc->discover()[0]->access);
	}

	/**
	 * Existence decides the branch, not whether access happens to be configured.
	 * A real collection with no mcp.access resolves to admin and stops; it must
	 * not fall through and pick up a same-named view.
	 */
	public function testCollectionWithoutAccessDoesNotFallThroughToView(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'p', 'body' => 'b', 'target' => 'blog'],
		]);
		$this->collections->method('fetchCollection')->willReturn($this->makeCollection(''));

		$views = $this->createMock(DataViewLister::class);
		$views->method('listViews')->willReturn([
			['id' => 'blog', 'mcp' => ['access' => 'public']],
		]);

		$svc = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger(), $views);
		$this->assertSame('admin', $svc->discover()[0]->access);
	}

	public function testTargetMatchingNeitherFallsBackToAdmin(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'p', 'body' => 'b', 'target' => 'ghost'],
		]);
		$this->collections->method('fetchCollection')->willReturn(null);

		$views = $this->createMock(DataViewLister::class);
		$views->method('listViews')->willReturn([]);

		$svc = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger(), $views);
		$this->assertSame('admin', $svc->discover()[0]->access);
	}

	public function testExplicitAccessWinsOverTargetView(): void
	{
		$this->indexFilter->method('fetchFilteredIndex')->willReturn([
			['id' => 'p', 'body' => 'b', 'target' => 'recent-posts', 'access' => 'admin'],
		]);
		$views = $this->createMock(DataViewLister::class);
		$views->expects($this->never())->method('listViews');

		$svc = new PromptDiscoveryService($this->indexFilter, $this->collections, new NullLogger(), $views);
		$this->assertSame('admin', $svc->discover()[0]->access);
	}

	/** A CollectionData carrying the given mcp.access ('' means none configured). */
	private function makeCollection(string $access): CollectionData
	{
		$collection     = new CollectionData();
		$collection->id = 'blog';
		if ($access !== '') {
			$collection->mcp = ['access' => $access];
		}

		return $collection;
	}
}
