<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Exception\EntrypointLoadException;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Turns a manifest's entrypoint file into its ExtensionInterface class:
 * require the extension's own Composer autoloader when it ships one, require
 * the entrypoint, and read the class name out of the file's tokens rather
 * than guessing it from the path.
 */
final class ExtensionEntrypointLoader
{
	/**
	 * @throws EntrypointLoadException When the entrypoint is missing, declares no class, or the class is not an extension
	 *
	 * @return class-string<ExtensionInterface>
	 */
	public function load(ExtensionManifest $manifest, string $extPath): string
	{
		$autoloadFile = $extPath . '/vendor/autoload.php';
		if (is_file($autoloadFile)) {
			require_once $autoloadFile;
		}

		$entrypointFile = $extPath . '/' . $manifest->entrypoint;
		if (!is_file($entrypointFile)) {
			throw new EntrypointLoadException("Entrypoint not found: {$manifest->entrypoint}");
		}

		require_once $entrypointFile;

		$className = self::classNameIn($entrypointFile);
		if ($className === null || !class_exists($className)) {
			throw new EntrypointLoadException("Extension class not found in {$manifest->entrypoint}");
		}

		if (!is_subclass_of($className, ExtensionInterface::class)) {
			throw new EntrypointLoadException('Extension class does not implement ExtensionInterface');
		}

		return $className;
	}

	/**
	 * The fully-qualified name of the first class a PHP file declares, from
	 * its tokens. `Foo::class` constant fetches are skipped.
	 */
	public static function classNameIn(string $filePath): ?string
	{
		if (!is_file($filePath)) {
			return null;
		}

		$contents = file_get_contents($filePath);
		if ($contents === false) {
			return null;
		}

		$tokens = token_get_all($contents);
		$count  = count($tokens);

		$namespace = '';
		$class     = '';

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!is_array($token)) {
				continue;
			}

			[$id] = $token;

			if ($id === T_NAMESPACE) {
				// Collect tokens until `;` or `{` — that's the namespace name.
				for ($j = $i + 1; $j < $count; $j++) {
					$next = $tokens[$j];
					if (is_string($next) && ($next === ';' || $next === '{')) {
						break;
					}
					if (is_array($next) && in_array($next[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
						$namespace .= $next[1];
					}
				}
				$namespace = trim($namespace);

				continue;
			}

			if ($id === T_CLASS) {
				// Skip `Foo::class` (T_CLASS preceded by `::`).
				$prev = $i - 1;
				while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
					$prev--;
				}
				if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
					continue;
				}

				// The class name is the next T_STRING token.
				for ($j = $i + 1; $j < $count; $j++) {
					$next = $tokens[$j];
					if (is_array($next) && $next[0] === T_STRING) {
						$class = $next[1];
						break 2;
					}
				}
			}
		}

		if ($class === '') {
			return null;
		}

		return $namespace !== '' ? $namespace . '\\' . $class : $class;
	}
}
