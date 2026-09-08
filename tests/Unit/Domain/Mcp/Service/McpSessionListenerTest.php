<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\McpSessionInvalidator;
use TotalCMS\Domain\Mcp\Service\McpSessionListener;
use TotalCMS\Support\Config;

/**
 * Every object write and every index build routes through
 * CollectionSaver::updateCollection() to bump count / totalObjects /
 * lastUpdated, and each of those dispatches `collection.updated`. Those
 * metadata-only updates carry `configChanged: false` and must NOT drop every
 * live MCP session — the "session not found" that used to follow any content
 * save (and the first MCP request in a fresh install, whose dataviews index
 * build hit the same cascade).
 */
final class McpSessionListenerTest extends TestCase
{
	private string $sessionsDir;
	private McpSessionListener $listener;

	protected function setUp(): void
	{
		$base              = sys_get_temp_dir() . '/mcp-listener-test-' . uniqid();
		$this->sessionsDir = $base . '/mcp-sessions';
		mkdir($this->sessionsDir, 0777, recursive: true);
		file_put_contents($this->sessionsDir . '/11111111-1111-4111-8111-111111111111', '{}');

		$config         = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->tmpdir = $base;

		$this->listener = new McpSessionListener(new McpSessionInvalidator($config));
	}

	protected function tearDown(): void
	{
		array_map('unlink', glob($this->sessionsDir . '/*') ?: []);
		@rmdir($this->sessionsDir);
		@rmdir(dirname($this->sessionsDir));
	}

	private function sessionCount(): int
	{
		return count(glob($this->sessionsDir . '/*') ?: []);
	}

	public function testAMetadataOnlyCollectionUpdateKeepsSessions(): void
	{
		$this->listener->onToolSurfaceChange(['collection' => 'blog', 'configChanged' => false]);

		$this->assertSame(1, $this->sessionCount());
	}

	public function testAConfigurationChangeDropsSessions(): void
	{
		$this->listener->onToolSurfaceChange(['collection' => 'blog', 'configChanged' => true]);

		$this->assertSame(0, $this->sessionCount());
	}

	public function testPayloadsWithoutTheFlagStillDropSessions(): void
	{
		// schema.saved, collection.created, collection.deleted carry no flag.
		$this->listener->onToolSurfaceChange(['collection' => 'blog']);

		$this->assertSame(0, $this->sessionCount());
	}
}
