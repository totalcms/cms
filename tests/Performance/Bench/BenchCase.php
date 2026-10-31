<?php

declare(strict_types=1);

namespace Tests\Performance\Bench;

use DI\Container;
use TotalCMS\TotalCMS;

/**
 * Shared boot for the scale benchmarks.
 *
 * phpbench runs every iteration in a fresh PHP process, so one iteration is
 * one "request": boot() points the process at a fixture install and starts
 * Total CMS in production mode. A warm subject primes the caches and then
 * calls freshRequest(), which builds a new container the way the next request
 * would get one: APCu (shared, like a real FPM pool) stays warm, every
 * per-request memo is gone.
 *
 * mem_peak is the peak of the measured request — container boot plus the
 * call — which is what a PHP worker on a customer's server holds for it. The
 * peak is reset at every fresh request so priming does not leak into it.
 */
abstract class BenchCase
{
	public const SIZES       = [100, 1000, 10000, 50000];
	public const PAGE_COUNTS = [10, 100, 500];

	protected Container $container;
	protected string $root = '';

	/** @return \Generator<string,array{fixture:string}> */
	public function provideSizes(): \Generator
	{
		foreach (self::fixtureStatus()['sizes']['available'] as $fixture) {
			yield $fixture => ['fixture' => $fixture];
		}
	}

	/** @return \Generator<string,array{fixture:string}> */
	public function providePageCounts(): \Generator
	{
		foreach (self::fixtureStatus()['pages']['available'] as $fixture) {
			yield $fixture => ['fixture' => $fixture];
		}
	}

	/**
	 * Which fixtures exist, and the command that builds each missing one.
	 * Missing sizes are simply left out of a run; tests/Performance/
	 * fixture-status.php prints them before phpbench starts (a provider's own
	 * output is swallowed by phpbench) and stops the run when a group is empty.
	 *
	 * @return array{sizes:array{available:list<string>,missing:list<string>},pages:array{available:list<string>,missing:list<string>}}
	 */
	public static function fixtureStatus(?string $base = null): array
	{
		$base ??= dirname(__DIR__, 3) . '/tmp/perf';
		$status = ['sizes' => ['available' => [], 'missing' => []], 'pages' => ['available' => [], 'missing' => []]];

		foreach (self::SIZES as $size) {
			$fixture = (string)$size;
			is_file("{$base}/{$fixture}/fixture.json")
				? $status['sizes']['available'][] = $fixture
				: $status['sizes']['missing'][]   = "php bin/perf-fixtures.php --size={$size}";
		}
		foreach (self::PAGE_COUNTS as $pages) {
			$fixture = '1000-p' . $pages;
			is_file("{$base}/{$fixture}/fixture.json")
				? $status['pages']['available'][] = $fixture
				: $status['pages']['missing'][]   = "php bin/perf-fixtures.php --size=1000 --pages={$pages} --root=tmp/perf/{$fixture}";
		}

		return $status;
	}

	/** @param array{fixture:string} $params */
	public function boot(array $params): void
	{
		$this->root = self::fixtureRoot($params['fixture']);

		$_SERVER['DOCUMENT_ROOT'] = $this->root . '/public';
		if (!defined('TCMS_PROJECT_ROOT')) {
			define('TCMS_PROJECT_ROOT', $this->root);
		}

		$this->freshRequest();
	}

	/** A new container, as the next request would build it. */
	protected function freshRequest(): void
	{
		unset($this->container);
		gc_collect_cycles();
		memory_reset_peak_usage();
		$this->container = (new TotalCMS(false))->container();
	}

	/** Empty every cache layer this fixture can hold, then start a fresh request. */
	protected function coldCaches(): void
	{
		if (function_exists('apcu_clear_cache')) {
			apcu_clear_cache();
		}

		foreach (glob($this->root . '/cache/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (basename($dir) !== 'container') {
				self::removeTree($dir);
			}
		}

		$this->freshRequest();
	}

	public static function fixtureRoot(string $fixture): string
	{
		return dirname(__DIR__, 3) . '/tmp/perf/' . $fixture;
	}

	private static function removeTree(string $path): void
	{
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}
}
