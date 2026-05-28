<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\McpSessionInvalidator;
use TotalCMS\Support\Config;

final class McpSessionInvalidatorTest extends TestCase
{
	private string $sessionsDir;
	private Config $config;

	protected function setUp(): void
	{
		// Use a unique tempdir per test so parallel runs don't collide.
		$base              = sys_get_temp_dir() . '/mcp-invalidator-test-' . uniqid();
		$this->sessionsDir = $base . '/mcp-sessions';
		mkdir($this->sessionsDir, 0777, recursive: true);

		$this->config         = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->tmpdir = $base;
	}

	protected function tearDown(): void
	{
		// Best-effort cleanup; the OS will reap /tmp eventually anyway.
		$this->deleteDirectory($this->sessionsDir);
		@rmdir(dirname($this->sessionsDir));
	}

	private function deleteDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $file) {
			if ($file === '.' || $file === '..') {
				continue;
			}
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
		}
		@rmdir($dir);
	}

	private function invalidator(): McpSessionInvalidator
	{
		return new McpSessionInvalidator($this->config);
	}

	// ─── invalidateAll ────────────────────────────────────────────────────────

	public function testInvalidateAllRemovesEverySessionFile(): void
	{
		// MCP client sessions live as opaque files under tmpdir/mcp-sessions.
		// Invalidation deletes them so the next request from any client fails
		// with "session not found" → most clients auto-reconnect → fresh
		// initialize → fresh tool list with the new surface.
		file_put_contents($this->sessionsDir . '/session-a', 'payload-a');
		file_put_contents($this->sessionsDir . '/session-b', 'payload-b');
		file_put_contents($this->sessionsDir . '/session-c', 'payload-c');

		$result = $this->invalidator()->invalidateAll();

		$this->assertSame(3, $result);
		$this->assertSame([], glob($this->sessionsDir . '/*') ?: []);
	}

	public function testInvalidateAllReturnsZeroWhenNoSessionsExist(): void
	{
		$this->assertSame(0, $this->invalidator()->invalidateAll());
	}

	public function testInvalidateAllToleratesMissingDirectory(): void
	{
		// Fresh install or scrubbed tmpdir — the directory may not exist yet.
		// The invalidator should treat that as "nothing to invalidate" rather
		// than erroring; the directory is created lazily on first session use.
		rmdir($this->sessionsDir);

		$this->assertSame(0, $this->invalidator()->invalidateAll());
	}

	public function testInvalidateAllSkipsSubdirectories(): void
	{
		// Defensive: in case the session store ever nests structure (some
		// FileSessionStore implementations do), don't recursively delete —
		// we only want top-level session files, and recursion would risk
		// blowing away unrelated tmp content if the path is misconfigured.
		mkdir($this->sessionsDir . '/some-subdir');
		file_put_contents($this->sessionsDir . '/some-subdir/nested', 'keep');
		file_put_contents($this->sessionsDir . '/session-a', 'remove');

		$result = $this->invalidator()->invalidateAll();

		$this->assertSame(1, $result);
		$this->assertFileExists($this->sessionsDir . '/some-subdir/nested');
		$this->assertFileDoesNotExist($this->sessionsDir . '/session-a');
	}

	public function testInvalidateAllSkipsDotfiles(): void
	{
		// Dotfiles (.DS_Store on macOS, .gitkeep on bare deploys) aren't
		// MCP sessions — leave them alone.
		file_put_contents($this->sessionsDir . '/.DS_Store', 'mac');
		file_put_contents($this->sessionsDir . '/session-real', 'go');

		$this->invalidator()->invalidateAll();

		$this->assertFileExists($this->sessionsDir . '/.DS_Store');
		$this->assertFileDoesNotExist($this->sessionsDir . '/session-real');
	}
}
