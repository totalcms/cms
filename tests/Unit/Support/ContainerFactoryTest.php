<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use TotalCMS\Support\ContainerFactory;

/**
 * ContainerFactory compiles the PHP-DI container in production. A stale/corrupt
 * compiled file is otherwise fatal on every request until deleted by hand, so
 * these pin the self-heal wipe and the cache-key shape (private statics, driven
 * via reflection).
 */
final class ContainerFactoryTest extends TestCase
{
	private function invokePrivate(string $method, mixed ...$args): mixed
	{
		// Private methods are invokable via reflection without setAccessible()
		// since PHP 8.1 (where it became a no-op).
		return (new \ReflectionMethod(ContainerFactory::class, $method))->invoke(null, ...$args);
	}

	public function testWipeCompiledContainerRemovesOnlyCompiledFiles(): void
	{
		$dir = sys_get_temp_dir() . '/tcms-container-' . bin2hex(random_bytes(4));
		mkdir($dir, 0755, true);

		try {
			file_put_contents($dir . '/CompiledContainer_abc123.php', '<?php // valid');
			file_put_contents($dir . '/CompiledContainer_def456.php', '<?php syntax error,'); // corrupt
			file_put_contents($dir . '/keep.txt', 'not a compiled container');

			$this->invokePrivate('wipeCompiledContainer', $dir);

			// Every compiled container file is gone (including the corrupt one)...
			$this->assertFileDoesNotExist($dir . '/CompiledContainer_abc123.php');
			$this->assertFileDoesNotExist($dir . '/CompiledContainer_def456.php');
			// ...but unrelated files are left alone.
			$this->assertFileExists($dir . '/keep.txt');
		} finally {
			foreach (glob($dir . '/*') ?: [] as $f) {
				@unlink($f);
			}
			@rmdir($dir);
		}
	}

	public function testWipeCompiledContainerIsSafeOnEmptyDir(): void
	{
		$dir = sys_get_temp_dir() . '/tcms-container-empty-' . bin2hex(random_bytes(4));
		mkdir($dir, 0755, true);

		try {
			// No compiled files present — must not error.
			$this->invokePrivate('wipeCompiledContainer', $dir);
			$this->assertDirectoryExists($dir);
		} finally {
			@rmdir($dir);
		}
	}

	public function testCompiledClassNameIsDeterministicAndPrefixed(): void
	{
		$name = $this->invokePrivate('compiledClassName');

		$this->assertIsString($name);
		$this->assertStringStartsWith('CompiledContainer_', $name);
		// Stable across calls for the same version + container.php.
		$this->assertSame($name, $this->invokePrivate('compiledClassName'));
	}
}
