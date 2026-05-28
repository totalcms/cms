<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Prompt\Handler;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Prompt\Handler\PromptChangeListener;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptDiscoveryService;

final class PromptChangeListenerTest extends TestCase
{
	/**
	 * Build a real PromptDiscoveryService with a stubbed IndexFilter that
	 * returns a controlled set of rows. We verify that clearCache() causes
	 * a re-read by counting the number of times fetchFilteredIndex() is
	 * called — once before, once after the cache is cleared.
	 */
	private function discoveryWithCallCount(int &$callCount): PromptDiscoveryService
	{
		$indexFilter = $this->createMock(IndexFilter::class);
		$indexFilter
			->method('fetchFilteredIndex')
			->willReturnCallback(function () use (&$callCount): array {
				$callCount++;

				return [];
			});

		$collections = $this->createMock(CollectionRepository::class);
		$collections->method('collectionExists')->willReturn(true);

		return new PromptDiscoveryService($indexFilter, $collections, new NullLogger());
	}

	public function testClearsCacheWhenMcpPromptObjectChanges(): void
	{
		$callCount = 0;
		$svc       = $this->discoveryWithCallCount($callCount);

		// Warm the cache.
		$svc->discover();
		$this->assertSame(1, $callCount);

		// Second call hits the cache — no extra read.
		$svc->discover();
		$this->assertSame(1, $callCount);

		// Listener fires for mcp-prompt collection → cache cleared.
		$listener = new PromptChangeListener($svc);
		$listener->onObjectChanged([
			'collection' => 'mcp-prompt',
			'id'         => 'draft_post',
		]);

		// discover() must re-read now.
		$svc->discover();
		$this->assertSame(2, $callCount);
	}

	public function testIgnoresOtherCollections(): void
	{
		$callCount = 0;
		$svc       = $this->discoveryWithCallCount($callCount);

		// Warm the cache.
		$svc->discover();
		$this->assertSame(1, $callCount);

		// Listener fires for a different collection → cache must NOT be cleared.
		$listener = new PromptChangeListener($svc);
		$listener->onObjectChanged([
			'collection' => 'blog',
			'id'         => 'post-1',
		]);

		// Still served from cache.
		$svc->discover();
		$this->assertSame(1, $callCount);
	}

	public function testHandlesEmptyPayload(): void
	{
		$callCount = 0;
		$svc       = $this->discoveryWithCallCount($callCount);

		$svc->discover();
		$this->assertSame(1, $callCount);

		$listener = new PromptChangeListener($svc);
		$listener->onObjectChanged([]);

		// No collection key → no cache clear.
		$svc->discover();
		$this->assertSame(1, $callCount);
	}

	public function testHandlesAllThreeEventTypes(): void
	{
		// All three object.* events use the same handler — verify three consecutive
		// clears each cause a fresh read.
		$callCount = 0;
		$svc       = $this->discoveryWithCallCount($callCount);

		$listener = new PromptChangeListener($svc);

		// created → clear → read
		$listener->onObjectChanged(['collection' => 'mcp-prompt', 'id' => 'p1']);
		$svc->discover();
		$this->assertSame(1, $callCount);

		// updated → clear → read
		$listener->onObjectChanged(['collection' => 'mcp-prompt', 'id' => 'p1']);
		$svc->discover();
		$this->assertSame(2, $callCount);

		// deleted → clear → read
		$listener->onObjectChanged(['collection' => 'mcp-prompt', 'id' => 'p1']);
		$svc->discover();
		$this->assertSame(3, $callCount);
	}
}
