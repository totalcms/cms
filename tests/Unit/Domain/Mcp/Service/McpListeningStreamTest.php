<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Mcp\Service\McpListeningStream;
use TotalCMS\Support\Config;

final class McpListeningStreamTest extends TestCase
{
	/** @param array<string,mixed> $mcp */
	private function config(array $mcp): Config
	{
		$config      = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->mcp = $mcp;

		return $config;
	}

	/** A CacheManager double backed by an array, so the counter is observable. */
	private function cache(array &$store): CacheManager
	{
		$cache = $this->createMock(CacheManager::class);
		$cache->method('getData')->willReturnCallback(static function (string $key) use (&$store): mixed {
			return $store[$key] ?? null;
		});
		$cache->method('storeData')->willReturnCallback(static function (string $key, mixed $value) use (&$store): bool {
			$store[$key] = $value;

			return true;
		});

		return $cache;
	}

	public function testSecondsIsClampedToZeroThirty(): void
	{
		$store = [];
		self::assertSame(1.0, (new McpListeningStream($this->cache($store), $this->config([])))->seconds());
		self::assertSame(30.0, (new McpListeningStream($this->cache($store), $this->config(['listeningStreamSeconds' => 999])))->seconds());
		self::assertSame(0.0, (new McpListeningStream($this->cache($store), $this->config(['listeningStreamSeconds' => -5])))->seconds());
		self::assertSame(2.5, (new McpListeningStream($this->cache($store), $this->config(['listeningStreamSeconds' => 2.5])))->seconds());
	}

	public function testReserveSlotCountsOpensUpToTheCap(): void
	{
		$store  = [];
		$stream = new McpListeningStream($this->cache($store), $this->config(['listeningStreamMaxConcurrent' => 2]));

		self::assertTrue($stream->reserveSlot(1.0));
		self::assertTrue($stream->reserveSlot(1.0));
		self::assertFalse($stream->reserveSlot(1.0), 'third open in the window is over the cap');
		self::assertSame(2, $store['mcp_listening_stream_slots']);
	}

	public function testReserveSlotIsDisabledByACapOfZeroOrAZeroWindow(): void
	{
		$store = [];
		self::assertTrue((new McpListeningStream($this->cache($store), $this->config(['listeningStreamMaxConcurrent' => 0])))->reserveSlot(1.0));
		self::assertTrue((new McpListeningStream($this->cache($store), $this->config(['listeningStreamMaxConcurrent' => 1])))->reserveSlot(0.0));
		self::assertArrayNotHasKey('mcp_listening_stream_slots', $store, 'no counting when the cap or the window is off');
	}

	public function testReserveSlotFailsOpenOnAGarbageCounter(): void
	{
		$store  = ['mcp_listening_stream_slots' => 'not-an-int'];
		$stream = new McpListeningStream($this->cache($store), $this->config(['listeningStreamMaxConcurrent' => 1]));

		self::assertTrue($stream->reserveSlot(1.0));
		self::assertSame(1, $store['mcp_listening_stream_slots']);
	}
}
