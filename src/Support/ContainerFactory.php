<?php

declare(strict_types=1);

namespace TotalCMS\Support;

use DI\Container;
use DI\ContainerBuilder;

/**
 * Builds the PHP-DI container with production compilation enabled.
 *
 * In production (`$config->env === 'prod'`), PHP-DI compiles every definition
 * — both the explicit closures in `config/container.php` AND the autowired
 * classes that aren't listed there — into a single optimized PHP class
 * cached under `{cachedir}/container/`. Subsequent requests skip reflection
 * and closure invocation entirely; resolving any class is a method dispatch
 * on the compiled container.
 *
 * The compiled class name is keyed off the app version/build AND `container.php`'s
 * mtime, so any update (or an edited container.php) rolls forward to a fresh
 * compiled class on first request — old compiled files are never loaded, which
 * avoids stale-definition bugs (and OPcache serving old bytecode for a reused
 * filename) without requiring a manual cache clear.
 *
 * A compiled container is `include`d once and only regenerated when it's absent,
 * so a stale or corrupt file (partial write, PHP-version mismatch, or a manual /
 * Stacks-publish update that never ran our cache-clearing updater) is a fatal
 * ParseError on EVERY request until the file is deleted by hand. To avoid that,
 * `build()` self-heals: if building the compiled container throws, it wipes the
 * compiled files and boots uncompiled for that one request; the next request
 * recompiles cleanly.
 *
 * Dev / test environments build an uncompiled container — definitions are
 * live-editable, no cache invalidation to think about.
 */
final class ContainerFactory
{
	public static function build(): Container
	{
		$config = Config::init();

		if ($config->env !== 'prod') {
			return self::builder()->build();
		}

		$cacheDir = $config->cachedir . '/container';
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0755, true);
		}

		// Can't compile (no writable cache dir) — run uncompiled.
		if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
			return self::builder()->build();
		}

		try {
			return self::builder()
				->enableCompilation($cacheDir, self::compiledClassName())
				->build();
		} catch (\Throwable) {
			// The compiled container is stale or corrupt (see class docblock).
			// Wipe it and boot uncompiled this once so the site stays up instead
			// of white-screening; the next request regenerates a clean compile.
			self::wipeCompiledContainer($cacheDir);

			return self::builder()->build();
		}
	}

	/**
	 * A fresh builder with the base definitions loaded. Used for both the
	 * compiled attempt and the uncompiled fallback, so neither reuses a builder
	 * left in a partial state.
	 *
	 * @return ContainerBuilder<Container>
	 */
	private static function builder(): ContainerBuilder
	{
		$builder = new ContainerBuilder();
		$builder->addDefinitions(PathResolver::packageRoot() . '/config/container.php');

		return $builder;
	}

	/**
	 * Class name for the compiled container — incorporates the app version/build
	 * and container.php's mtime so a new build or an edited container.php produces
	 * a new class. Prevents stale-cache bugs on updates without a manual clear.
	 */
	private static function compiledClassName(): string
	{
		$path  = PathResolver::packageRoot() . '/config/container.php';
		$mtime = (int)@filemtime($path);
		$key   = Version::get() . '|' . $mtime . '|' . $path;

		return 'CompiledContainer_' . substr(sha1($key), 0, 12);
	}

	/**
	 * Delete every compiled container file so the next request regenerates one,
	 * invalidating OPcache per path in case a reused filename would otherwise
	 * serve stale/corrupt bytecode.
	 */
	private static function wipeCompiledContainer(string $cacheDir): void
	{
		foreach (glob($cacheDir . '/CompiledContainer_*.php') ?: [] as $file) {
			if (function_exists('opcache_invalidate')) {
				@opcache_invalidate($file, true);
			}
			@unlink($file);
		}
	}
}
