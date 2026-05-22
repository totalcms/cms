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
 * The compiled class name is keyed off `container.php`'s mtime so a deploy
 * that ships a new container.php transparently rolls forward to a fresh
 * compiled class on first request. Old compiled files accumulate under the
 * cache directory; they're small (~150KB each) and get swept whenever
 * `CacheManager::clearAllCaches()` clears `cachedir`.
 *
 * Dev / test environments build an uncompiled container — definitions are
 * live-editable, no cache invalidation to think about. This matches how
 * `new Container(...)` behaved before this factory existed.
 */
final class ContainerFactory
{
	public static function build(): Container
	{
		$config = Config::init();

		$builder = new ContainerBuilder();
		$builder->addDefinitions(PathResolver::packageRoot() . '/config/container.php');

		if ($config->env === 'prod') {
			$cacheDir = $config->cachedir . '/container';
			if (!is_dir($cacheDir)) {
				@mkdir($cacheDir, 0755, true);
			}

			if (is_dir($cacheDir) && is_writable($cacheDir)) {
				$builder->enableCompilation($cacheDir, self::compiledClassName());
			}
		}

		return $builder->build();
	}

	/**
	 * Class name for the compiled container — incorporates container.php's
	 * mtime so a new file produces a new class. Prevents stale-cache bugs
	 * on deploys without requiring a manual cache clear.
	 */
	private static function compiledClassName(): string
	{
		$path  = PathResolver::packageRoot() . '/config/container.php';
		$mtime = (int)@filemtime($path);

		return 'CompiledContainer_' . substr(sha1((string)$mtime . $path), 0, 12);
	}
}
