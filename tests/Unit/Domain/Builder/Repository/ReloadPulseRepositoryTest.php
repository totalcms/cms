<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Repository;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Repository\ReloadPulseRepository;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Unit tests for the live-reload pulse file. Backed by a real filesystem
 * (sys_get_temp_dir) rather than a mocked storage adapter — the atomic
 * temp+rename pattern is a core invariant we don't want to mock past.
 */
final class ReloadPulseRepositoryTest extends TestCase
{
	private string $tmpRoot;
	private ReloadPulseRepository $repo;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-pulse-' . uniqid();
		mkdir($this->tmpRoot . '/.system', 0755, true);

		$flysystem  = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage    = new StorageFilesystemAdapter($flysystem);
		$this->repo = new ReloadPulseRepository($storage);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpRoot);
	}

	public function testCurrentReturnsNullBeforeFirstPulse(): void
	{
		$this->assertNull($this->repo->current());
		$this->assertSame(0, $this->repo->currentTs());
	}

	public function testPulseWritesPayload(): void
	{
		$this->repo->pulse('pages/about');

		$current = $this->repo->current();
		$this->assertNotNull($current);
		$this->assertGreaterThan(0, $current['ts']);
		$this->assertSame('pages/about', $current['path']);
	}

	public function testPulseAdvancesTimestamp(): void
	{
		$this->repo->pulse('pages/about');
		$first = $this->repo->currentTs();

		// Sleep just past 1ms so the millisecond stamp can advance.
		usleep(2000);

		$this->repo->pulse('pages/contact');
		$second = $this->repo->currentTs();

		$this->assertGreaterThan($first, $second);
	}

	public function testPulseUsesMillisecondPrecision(): void
	{
		// Bare unix seconds would coalesce rapid saves. We need millisecond
		// resolution so two pulses ~5ms apart are distinguishable.
		$this->repo->pulse('a');
		$first = $this->repo->currentTs();
		usleep(5000);
		$this->repo->pulse('b');
		$second = $this->repo->currentTs();

		$delta = $second - $first;
		$this->assertGreaterThan(0, $delta);
		// Sanity check: the timestamp lives in milliseconds, so the delta for
		// a 5ms wait should be in the single-digit-millisecond range, not
		// microseconds (which would mean we're stamping in seconds and the
		// underlying granularity is wrong).
		$this->assertLessThan(1000, $delta, 'pulse timestamp granularity is wrong');
	}

	public function testEmptyPathIsAllowed(): void
	{
		$this->repo->pulse();
		$this->assertSame('', $this->repo->current()['path'] ?? 'unset');
	}

	public function testCorruptedFileReturnsNull(): void
	{
		// A non-JSON file in the pulse path shouldn't crash readers — readers
		// must degrade to "no pulse seen yet" rather than throwing.
		file_put_contents($this->tmpRoot . '/' . ReloadPulseRepository::PULSE_FILE, 'not json');

		$this->assertNull($this->repo->current());
		$this->assertSame(0, $this->repo->currentTs());
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
